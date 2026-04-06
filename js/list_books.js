
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

document.addEventListener('DOMContentLoaded', () => {
  const bodyData = document.body.dataset;
  const totalPages = parseInt(bodyData.totalPages, 10);
  const fetchUrlBase = bodyData.baseUrl;
  const perPage = parseInt(bodyData.perPage, 10);

  const contentArea = document.getElementById('contentArea');
  const topSentinel = document.getElementById('topSentinel');
  const bottomSentinel = document.getElementById('bottomSentinel');
  const pageNav = document.getElementById('pageNav');
  if (pageNav) {
    pageNav.classList.add('d-none');
  }
  const openLibraryModalEl = document.getElementById('openLibraryModal');
  const openLibraryModal = new bootstrap.Modal(openLibraryModalEl);

  const sendResultModalEl = document.getElementById('sendResultModal');
  const sendResultModal   = sendResultModalEl ? new bootstrap.Modal(sendResultModalEl) : null;

  const confirmModalEl  = document.getElementById('confirmModal');
  const confirmModal    = confirmModalEl ? new bootstrap.Modal(confirmModalEl) : null;

  // Author info modal
  const authorModalEl    = document.getElementById('authorModal');
  const authorModal      = authorModalEl ? new bootstrap.Modal(authorModalEl) : null;
  const authorModalBody  = document.getElementById('authorModalBody');
  const authorModalLabel = document.getElementById('authorModalLabel');
  const authorFilterLink = document.getElementById('authorModalFilterLink');
  const authorSaveBioBtn = document.getElementById('authorModalSaveBio');
  const authorBioStatus  = document.getElementById('authorModalBioStatus');
  let _currentAuthorId   = null;

  authorSaveBioBtn?.addEventListener('click', () => {
    if (!_currentAuthorId) return;
    const textarea = authorModalBody?.querySelector('#authorBioTextarea');
    if (!textarea) return;
    const bio = textarea.value.trim();
    authorBioStatus.textContent = 'Saving…';
    fetch('json_endpoints/save_author_bio.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ author_id: _currentAuthorId, bio })
    })
      .then(r => r.json())
      .then(data => {
        if (data.status === 'ok') {
          authorBioStatus.textContent = 'Saved';
          setTimeout(() => { authorBioStatus.textContent = ''; }, 2000);
        } else {
          authorBioStatus.textContent = data.error || 'Error';
        }
      })
      .catch(() => { authorBioStatus.textContent = 'Network error'; });
  });
  const confirmTitle    = document.getElementById('confirmModalTitle');
  const confirmBody     = document.getElementById('confirmModalBody');
  const confirmHeader   = document.getElementById('confirmModalHeader');
  const confirmOkBtn    = document.getElementById('confirmModalOk');

  /** Mark a book row as now on-device: swap send button → remove button, add title icon. */
  function markRowOnDevice(row, bookId, devicePath) {
    // Add tablet icon after book title link if not already there
    const titleSpan = row.querySelector('.book-title');
    if (titleSpan && !titleSpan.querySelector('.fa-tablet-screen-button')) {
      const icon = document.createElement('i');
      icon.className = 'fa-solid fa-tablet-screen-button text-success ms-1';
      icon.title = 'On device';
      titleSpan.appendChild(icon);
    }
    // Swap send button → remove button
    const sendBtn = row.querySelector('.send-to-device-row');
    if (sendBtn) {
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'btn btn-sm btn-outline-warning remove-from-device-row py-0 px-2';
      removeBtn.dataset.bookId = bookId;
      removeBtn.dataset.devicePath = devicePath;
      removeBtn.title = 'Remove from device';
      removeBtn.innerHTML = '<i class="fa-solid fa-tablet-screen-button"></i>';
      sendBtn.replaceWith(removeBtn);
    }
  }

  /** Mark a book row as removed from device: swap remove button → send button, remove title icon. */
  function markRowOffDevice(row) {
    row.querySelectorAll('.fa-tablet-screen-button').forEach(el => {
      // Keep icons that are inside buttons (the remove button itself will be swapped below)
      if (!el.closest('button')) el.remove();
    });
    const removeBtn = row.querySelector('.remove-from-device-row');
    if (removeBtn) {
      const bookId = removeBtn.dataset.bookId;
      const sendBtn = document.createElement('button');
      sendBtn.type = 'button';
      sendBtn.className = 'btn btn-sm btn-outline-success send-to-device-row py-0 px-2';
      sendBtn.dataset.bookId = bookId;
      sendBtn.title = 'Send to device';
      sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
      removeBtn.replaceWith(sendBtn);
    }
  }

  /** Returns a Promise<boolean> — resolves true if user clicks Confirm, false otherwise. */
  function showConfirm(title, body, variant = 'danger') {
    return new Promise(resolve => {
      if (!confirmModal) { resolve(window.confirm(body)); return; }
      confirmTitle.textContent  = title;
      confirmBody.textContent   = body;
      confirmHeader.className   = `modal-header bg-${variant} text-white`;
      confirmOkBtn.className    = `btn btn-${variant}`;
      // Replace confirm button to wipe old listeners
      const fresh = confirmOkBtn.cloneNode(true);
      confirmOkBtn.replaceWith(fresh);
      let resolved = false;
      fresh.addEventListener('click', () => {
        resolved = true;
        confirmModal.hide();
        resolve(true);
      });
      confirmModalEl.addEventListener('hidden.bs.modal', () => {
        if (!resolved) resolve(false);
      }, { once: true });
      confirmModal.show();
    });
  }
  function showSendResult(success, message, detail, items) {
    if (!sendResultModal) return;
    const header = document.getElementById('sendResultHeader');
    const title  = document.getElementById('sendResultTitle');
    const body   = document.getElementById('sendResultBody');
    header.className = 'modal-header ' + (success ? 'bg-success text-white' : 'bg-danger text-white');
    title.textContent = success ? 'Sent successfully' : 'Send failed';
    let html = '<p class="mb-0">' + escapeHTML(message) + '</p>';
    if (items && items.length) {
      html += '<ul class="list-unstyled mt-2 mb-0 small" style="max-height:260px;overflow-y:auto">';
      items.forEach(it => {
        const icon = it.ok
          ? '<i class="fa-solid fa-check text-success me-2"></i>'
          : '<i class="fa-solid fa-xmark text-danger me-2"></i>';
        html += '<li class="py-1 border-bottom">' + icon + escapeHTML(it.text) + '</li>';
      });
      html += '</ul>';
    } else if (detail) {
      html += '<pre class="mt-2 small text-muted">' + escapeHTML(detail) + '</pre>';
    }
    body.innerHTML = html;
    sendResultModal.show();
  }

  const loadingSpinner = document.getElementById('loadingSpinner');
  let activeLoads = 0;
  async function showSpinner() {
    if (loadingSpinner && activeLoads++ === 0) {
      loadingSpinner.classList.remove('d-none');
      // Yield to the browser so the spinner can render
      await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
    }
  }
  function hideSpinner() {
    if (loadingSpinner && --activeLoads <= 0) {
      activeLoads = 0;
      loadingSpinner.classList.add('d-none');
    }
  }

  initCoverDimensions(contentArea);

  let lowestPage  = parseInt(bodyData.page, 10);
  let highestPage = lowestPage;
  let isLoading   = false;

  // Spacer elements maintain accurate scrollbar size when pages are trimmed.
  const topSpacer    = document.getElementById('topSpacer');
  const bottomSpacer = document.getElementById('bottomSpacer');

  // Measured heights of trimmed pages so spacers stay accurate.
  const trimmedTopHeights    = [];   // one entry per trimmed page from the top
  const trimmedBottomHeights = [];   // one entry per trimmed page from the bottom

  function measurePageHeight(page) {
    const start = (page - 1) * perPage;
    const end   = start + perPage;
    let h = 0;
    contentArea.querySelectorAll('.list-item').forEach(item => {
      const i = parseInt(item.dataset.bookIndex, 10);
      if (i >= start && i < end) h += item.getBoundingClientRect().height;
    });
    return h;
  }

  function updateSpacers() {
    const topH    = trimmedTopHeights.reduce((a, b) => a + b, 0);
    const bottomH = trimmedBottomHeights.reduce((a, b) => a + b, 0);
    if (topSpacer)    topSpacer.style.height    = topH    + 'px';
    if (bottomSpacer) bottomSpacer.style.height = bottomH + 'px';
  }

  // ── AbortController map for in-flight fetches ──────────────────────────────
  const fetchControllers = new Map();   // page → AbortController

  function fetchPage(p) {
    // Cancel any existing fetch for this page before starting a new one
    if (fetchControllers.has(p)) fetchControllers.get(p).abort();
    const ctrl = new AbortController();
    fetchControllers.set(p, ctrl);

    return fetch(fetchUrlBase + p + '&ajax=1', { signal: ctrl.signal })
      .then(res => res.text())
      .then(html => {
        fetchControllers.delete(p);
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        return Array.from(tmp.children);
      });
  }

  // ── Prefetch cache ─────────────────────────────────────────────────────────
  let nextCache = new Map();
  let prevCache = new Map();

  function prefetchNext() {
    for (let p = highestPage + 1; p <= Math.min(highestPage + 2, totalPages); p++) {
      if (nextCache.has(p)) continue;
      const prom = fetchPage(p).catch(err => {
        if (err.name !== 'AbortError') console.error(err);
        nextCache.delete(p);
      });
      nextCache.set(p, prom);
    }
  }

  function prefetchPrevious() {
    for (let p = lowestPage - 1; p >= Math.max(lowestPage - 2, 1); p--) {
      if (prevCache.has(p)) continue;
      const prom = fetchPage(p).catch(err => {
        if (err.name !== 'AbortError') console.error(err);
        prevCache.delete(p);
      });
      prevCache.set(p, prom);
    }
  }

  async function loadNext() {
    if (highestPage >= totalPages || isLoading) return;
    isLoading = true;
    const p = highestPage + 1;
    let prom = nextCache.get(p);
    if (!prom) { prom = fetchPage(p); nextCache.set(p, prom); }
    await showSpinner();
    try {
      const els = await prom;
      nextCache.delete(p);
      if (p !== highestPage + 1) return;   // stale — another load beat us
      els.forEach(el => bottomSentinel.parentNode.insertBefore(el, bottomSentinel));
      initCoverDimensions(els);
      observeItems(els);
      highestPage = p;
      // Restore any bottom spacer height consumed by this page
      if (trimmedBottomHeights.length > 0) {
        trimmedBottomHeights.pop();
        updateSpacers();
      }
      prefetchNext();
      prefetchPrevious();
      trimPages();
    } catch (err) {
      if (err.name !== 'AbortError') console.error(err);
      nextCache.delete(p);
    } finally {
      await new Promise(requestAnimationFrame);
      hideSpinner();
      isLoading = false;
    }
  }

  async function loadPrevious() {
    if (lowestPage <= 1 || isLoading) return;
    isLoading = true;
    const p = lowestPage - 1;
    let prom = prevCache.get(p);
    if (!prom) { prom = fetchPage(p); prevCache.set(p, prom); }
    await showSpinner();
    try {
      const els = await prom;
      prevCache.delete(p);
      if (p !== lowestPage - 1) return;    // stale
      const anchor    = contentArea.querySelector('.list-item');
      const anchorTop = anchor ? anchor.getBoundingClientRect().top : 0;

      // Shrink spacer BEFORE inserting items so the two operations cancel out
      // (net document-height change ≈ 0, no scroll compensation needed)
      if (trimmedTopHeights.length > 0) {
        trimmedTopHeights.pop();
        updateSpacers();
      }

      const frag = document.createDocumentFragment();
      els.forEach(el => frag.appendChild(el));
      topSentinel.parentNode.insertBefore(frag, topSentinel.nextSibling);
      initCoverDimensions(els);
      observeItems(els);
      lowestPage = p;

      // Correct any residual difference (e.g. images not yet loaded)
      if (anchor && anchor.isConnected) {
        window.scrollBy(0, anchor.getBoundingClientRect().top - anchorTop);
      }
      prefetchPrevious();
      prefetchNext();
      trimPages();
    } catch (err) {
      if (err.name !== 'AbortError') console.error(err);
      prevCache.delete(p);
    } finally {
      await new Promise(requestAnimationFrame);
      hideSpinner();
      isLoading = false;
    }
  }

  prefetchNext();
  prefetchPrevious();

  const bottomObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => { if (entry.isIntersecting) loadNext(); });
  });
  bottomObserver.observe(bottomSentinel);

  const topObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => { if (entry.isIntersecting) loadPrevious(); });
  });
  topObserver.observe(topSentinel);

  // ── Track first visible item via IntersectionObserver ─────────────────────
  // More efficient than a linear DOM scan on every scroll event.
  let firstVisibleIndex = null;
  const visibilityObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      const idx = parseInt(entry.target.dataset.bookIndex, 10);
      if (entry.isIntersecting) {
        if (firstVisibleIndex === null || idx < firstVisibleIndex) {
          firstVisibleIndex = idx;
        }
      } else {
        if (idx === firstVisibleIndex) firstVisibleIndex = null;
      }
    });
  }, { rootMargin: '0px 0px -90% 0px' });   // fires when item enters top 10% of viewport

  function observeItems(els) {
    const items = Array.isArray(els)
      ? els.flatMap(el => el.classList?.contains('list-item') ? [el] : Array.from(el.querySelectorAll?.('.list-item') ?? []))
      : Array.from((els instanceof Node ? els : document).querySelectorAll('.list-item'));
    items.forEach(item => visibilityObserver.observe(item));
  }
  observeItems(contentArea);

  function currentItemIndex() {
    if (firstVisibleIndex !== null) return String(firstVisibleIndex);
    // Fallback: linear scan (should rarely be needed)
    for (const item of contentArea.querySelectorAll('.list-item')) {
      if (item.getBoundingClientRect().bottom > 0) return item.dataset.bookIndex;
    }
    return null;
  }

  // ── Trim pages outside ±2 window ───────────────────────────────────────────
  function trimPages() {
    const idx = currentItemIndex();
    if (idx === null) return;
    const currentPage = Math.floor(parseInt(idx, 10) / perPage) + 1;
    const minPage = Math.max(1, currentPage - 2);
    const maxPage = Math.min(totalPages, currentPage + 2);

    topObserver.unobserve(topSentinel);
    bottomObserver.unobserve(bottomSentinel);

    while (lowestPage < minPage) {
      const h = measurePageHeight(lowestPage);
      const start = (lowestPage - 1) * perPage;
      const end   = start + perPage;
      contentArea.querySelectorAll('.list-item').forEach(item => {
        const i = parseInt(item.dataset.bookIndex, 10);
        if (i >= start && i < end) { visibilityObserver.unobserve(item); item.remove(); }
      });
      trimmedTopHeights.push(h);
      lowestPage++;
    }

    while (highestPage > maxPage) {
      const h = measurePageHeight(highestPage);
      const start = (highestPage - 1) * perPage;
      const end   = start + perPage;
      contentArea.querySelectorAll('.list-item').forEach(item => {
        const i = parseInt(item.dataset.bookIndex, 10);
        if (i >= start && i < end) { visibilityObserver.unobserve(item); item.remove(); }
      });
      trimmedBottomHeights.push(h);
      highestPage--;
    }

    updateSpacers();

    bottomObserver.observe(bottomSentinel);
    topObserver.observe(topSentinel);

    prefetchNext();
    prefetchPrevious();
  }

  // ── State save: sessionStorage + URL bar ───────────────────────────────────
  function saveState() {
    if (skipSave) return;
    const idx = currentItemIndex();
    if (idx === null) return;
    sessionStorage.setItem('lastItem', idx);

    // Keep the URL page param in sync with where the user actually is
    const visiblePage = Math.floor(parseInt(idx, 10) / perPage) + 1;
    const url = new URL(window.location.href);
    if (url.searchParams.get('page') !== String(visiblePage)) {
      url.searchParams.set('page', visiblePage);
      history.replaceState(null, '', url.toString());
    }
  }

  let scrollTimer = null;
  window.addEventListener('scroll', () => {
    if (scrollTimer) return;
    scrollTimer = setTimeout(() => {
      trimPages();
      saveState();
      scrollTimer = null;
    }, 200);
  }, { passive: true });

  document.addEventListener('change', async e => {
    if (e.target.classList.contains('shelf-select')) {
      const bookId = e.target.dataset.bookId;
      const value = e.target.value;
      await fetch('json_endpoints/update_shelf.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: bookId, value })
      });
    } else if (e.target.classList.contains('genre-select')) {
      const bookId = e.target.dataset.bookId;
      const value = e.target.value;
      await fetch('json_endpoints/update_genre.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: bookId, value })
      });
    } else if (e.target.classList.contains('status-select')) {
      const bookId = e.target.dataset.bookId;
      const value = e.target.value;
      await fetch('json_endpoints/update_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: bookId, value })
      });
    } else if (e.target.classList.contains('rating-select')) {
      const bookId = e.target.dataset.bookId;
      const value = e.target.value;
      await fetch('json_endpoints/update_rating.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: new URLSearchParams({ book_id: bookId, value })
      });
    }
  });

  document.addEventListener('change', async e => {
    if (e.target.classList.contains('series-index-input')) {
      const bookId = e.target.dataset.bookId;
      await fetch('json_endpoints/update_series.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: bookId, series_index: e.target.value })
      });
    }
  });

  // Series autocomplete
  const allSeries = window.seriesList || [];

  function getSeriesSuggestions(query) {
    const q = query.toLowerCase();
    return allSeries.filter(s => s.name.toLowerCase().includes(q));
  }

  function showSeriesSuggestions(input, suggestions) {
    const ul = input.parentElement.querySelector('.series-suggestions');
    if (!ul) return;
    ul.innerHTML = '';
    if (suggestions.length === 0) {
      ul.style.display = 'none';
      return;
    }
    suggestions.forEach(s => {
      const li = document.createElement('li');
      li.className = 'list-group-item list-group-item-action py-1 px-2 small';
      li.textContent = s.name;
      li.dataset.seriesId = s.id;
      ul.appendChild(li);
    });
    ul.style.display = 'block';
  }

  function hideSeriesSuggestions(input) {
    const ul = input.parentElement.querySelector('.series-suggestions');
    if (ul) ul.style.display = 'none';
  }

  async function commitSeriesName(input) {
    const name = input.value.trim();
    const bookId = input.dataset.bookId;
    const res = await fetch('json_endpoints/update_series.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ book_id: bookId, series_name: name })
    });
    const data = await res.json();
    if (data.series_id) {
      input.dataset.seriesId = data.series_id;
      // Add to local list if newly created
      if (!allSeries.find(s => s.id === data.series_id)) {
        allSeries.push({ id: data.series_id, name: data.series_name });
        allSeries.sort((a, b) => a.name.localeCompare(b.name));
      }
    } else {
      input.dataset.seriesId = '';
    }
  }

  document.addEventListener('input', e => {
    if (!e.target.classList.contains('series-name-input')) return;
    const input = e.target;
    const q = input.value.trim();
    if (q === '') {
      hideSeriesSuggestions(input);
      return;
    }
    showSeriesSuggestions(input, getSeriesSuggestions(q));
  });

  document.addEventListener('keydown', e => {
    if (!e.target.classList.contains('series-name-input')) return;
    if (e.key === 'Escape') {
      hideSeriesSuggestions(e.target);
      e.target.value = '';
      commitSeriesName(e.target);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      hideSeriesSuggestions(e.target);
      commitSeriesName(e.target);
      e.target.blur();
    }
  });

  document.addEventListener('focusout', e => {
    if (!e.target.classList.contains('series-name-input')) return;
    // Delay to allow suggestion click to fire first
    setTimeout(() => {
      hideSeriesSuggestions(e.target);
      commitSeriesName(e.target);
    }, 150);
  });

  // Suggestion click
  document.addEventListener('mousedown', e => {
    const li = e.target.closest('.series-suggestions .list-group-item');
    if (!li) return;
    e.preventDefault();
    const ul = li.closest('.series-suggestions');
    const input = ul.parentElement.querySelector('.series-name-input');
    if (!input) return;
    input.value = li.textContent;
    input.dataset.seriesId = li.dataset.seriesId;
    hideSeriesSuggestions(input);
    const bookId = input.dataset.bookId;
    fetch('json_endpoints/update_series.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ book_id: bookId, series_id: li.dataset.seriesId })
    });
  });

  const addShelfForm = document.getElementById('addShelfForm');
  if (addShelfForm) {
    addShelfForm.addEventListener('submit', async e => {
      e.preventDefault();
      const shelf = addShelfForm.querySelector('input[name="shelf"]').value.trim();
      if (!shelf) return;
      try {
        const res = await fetch('json_endpoints/add_shelf.php', {
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
        const res = await fetch('json_endpoints/add_status.php', {
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
        const res = await fetch('json_endpoints/add_genre.php', {
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
          await fetch('json_endpoints/update_rating.php', {
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
        const res = await fetch('json_endpoints/delete_shelf.php', {
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
        const res = await fetch('json_endpoints/rename_shelf.php', {
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
        const res = await fetch('json_endpoints/delete_status.php', {
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
        const res = await fetch('json_endpoints/delete_genre.php', {
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

    const sendBtn = e.target.closest('.send-to-device-row');
    if (sendBtn) {
      const bookId = sendBtn.dataset.bookId;
      const origHtml = sendBtn.innerHTML;
      sendBtn.disabled = true;
      sendBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
      try {
        const fd = new FormData();
        fd.append('book_id', bookId);
        await fetch('json_endpoints/write_ebook_metadata.php', { method: 'POST', body: fd });
      } catch (err) { console.warn('[send-to-device] metadata write error:', err); }
      try {
        const res  = await fetch('json_endpoints/send_to_device.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ book_id: bookId })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          showSendResult(true, 'Sent to ' + data.destination);
          if (data.device_path) {
            const row = sendBtn.closest('[data-book-block-id]');
            if (row) markRowOnDevice(row, bookId, data.device_path);
          }
          fetch('json_endpoints/sync_device.php', { method: 'POST' }); // background sync
        } else {
          showSendResult(false, data.error || 'Send failed.', data.detail || '');
        }
      } catch (err) {
        showSendResult(false, 'Request error: ' + err.message);
      }
      sendBtn.disabled = false;
      sendBtn.innerHTML = origHtml;
      return;
    }

    const removeDevBtn = e.target.closest('.remove-from-device-row');
    if (removeDevBtn) {
      // Two-click confirmation: first click arms the button, second click fires
      if (!removeDevBtn.dataset.armed) {
        removeDevBtn.dataset.armed = '1';
        const origHtml = removeDevBtn.innerHTML;
        removeDevBtn.innerHTML = '<i class="fa-solid fa-circle-exclamation me-1"></i>Sure?';
        removeDevBtn.classList.remove('btn-outline-warning');
        removeDevBtn.classList.add('btn-danger');
        const timer = setTimeout(() => {
          if (removeDevBtn.dataset.armed) {
            delete removeDevBtn.dataset.armed;
            removeDevBtn.innerHTML = origHtml;
            removeDevBtn.classList.remove('btn-danger');
            removeDevBtn.classList.add('btn-outline-warning');
          }
        }, 3000);
        removeDevBtn.dataset.resetTimer = timer;
        return;
      }
      // Second click — execute removal
      clearTimeout(parseInt(removeDevBtn.dataset.resetTimer, 10));
      delete removeDevBtn.dataset.armed;
      delete removeDevBtn.dataset.resetTimer;
      const devicePath = removeDevBtn.dataset.devicePath;
      removeDevBtn.disabled = true;
      removeDevBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
      try {
        const res  = await fetch('json_endpoints/remove_from_device.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ device_path: devicePath })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          const row = removeDevBtn.closest('[data-book-block-id]');
          markRowOffDevice(row);
          showSendResult(true, 'Removed from device.');
        } else {
          showSendResult(false, data.error || 'Remove failed.', data.detail || '');
          removeDevBtn.disabled = false;
          removeDevBtn.innerHTML = '<i class="fa-solid fa-tablet-screen-button"></i>';
          removeDevBtn.classList.remove('btn-danger');
          removeDevBtn.classList.add('btn-outline-warning');
        }
      } catch (err) {
        showSendResult(false, 'Request error: ' + err.message);
        removeDevBtn.disabled = false;
        removeDevBtn.innerHTML = '<i class="fa-solid fa-tablet-screen-button"></i>';
        removeDevBtn.classList.remove('btn-danger');
        removeDevBtn.classList.add('btn-outline-warning');
      }
      return;
    }

    const delBookBtn = e.target.closest('.delete-book');
    if (delBookBtn) {
      if (!confirm('Are you sure you want to permanently delete this book?')) return;
      const bookId = delBookBtn.dataset.bookId;
      try {
        const res = await fetch('json_endpoints/delete_book.php', {
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
        const res = await fetch('json_endpoints/rename_status.php', {
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
        const res = await fetch('json_endpoints/rename_genre.php', {
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

  // ── Inline title editing ─────────────────────────────────────────────────
  document.addEventListener('click', ev => {
    const editBtn = ev.target.closest('.title-edit-btn');
    if (!editBtn) return;
    ev.preventDefault();

    const bookId   = editBtn.dataset.bookId;
    const titleLink = editBtn.previousElementSibling;
    if (!titleLink || titleLink.querySelector('input')) return; // already editing

    const currentTitle = titleLink.textContent.trim();

    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentTitle;
    input.className = 'form-control form-control-sm d-inline-block';
    input.style.cssText = 'width:22rem;max-width:100%;vertical-align:baseline';

    titleLink.classList.add('d-none');
    editBtn.classList.add('d-none');
    editBtn.parentElement.insertBefore(input, editBtn);
    input.focus();
    input.select();

    let done = false;

    async function saveTitle() {
      if (done) return;
      done = true;
      const newTitle = input.value.trim();
      restore();
      if (newTitle === '' || newTitle === currentTitle) return;
      try {
        const res  = await fetch('json_endpoints/update_metadata.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ book_id: bookId, title: newTitle })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          titleLink.textContent = newTitle;
          const block = editBtn.closest('[data-book-block-id]');
          if (block) {
            const descEl = block.querySelector('.book-description');
            if (descEl) descEl.dataset.title = newTitle;
          }
        } else {
          titleLink.textContent = currentTitle;
          alert(data.error || 'Error saving title');
        }
      } catch (e) {
        titleLink.textContent = currentTitle;
      }
    }

    function restore() {
      input.remove();
      titleLink.classList.remove('d-none');
      editBtn.classList.remove('d-none');
    }

    input.addEventListener('keydown', e => {
      if (e.key === 'Enter')  { e.preventDefault(); saveTitle(); }
      if (e.key === 'Escape') { done = true; restore(); }
    });
    input.addEventListener('blur', () => saveTitle());
  });

  document.addEventListener('click', async ev => {
    const metaBtn = ev.target.closest('.openlibrary-meta');
    const resultsEl = document.getElementById('openLibraryResults');
    if (metaBtn) {
      const bookId = metaBtn.dataset.bookId;
      const query  = metaBtn.dataset.search || '';
      const isbn   = metaBtn.dataset.isbn   || '';
      const olid   = metaBtn.dataset.olid   || '';
      if (resultsEl) resultsEl.innerHTML = recsSpinner;
      openLibraryModal.show();

      const olUrl = olid
        ? `json_endpoints/openlibrary_search.php?olid=${encodeURIComponent(olid)}`
        : isbn
          ? `json_endpoints/openlibrary_search.php?isbn=${encodeURIComponent(isbn)}&q=${encodeURIComponent(query)}`
          : `json_endpoints/openlibrary_search.php?q=${encodeURIComponent(query)}`;

      fetch(olUrl)
        .then(r => r.json())
        .then(data => {
          if (!data.books || data.books.length === 0) {
            if (resultsEl) resultsEl.textContent = 'No results found';
            return;
          }
          const html = data.books.map(b => {
            const title     = escapeHTML(b.title     || '');
            const author    = escapeHTML(b.authors   || '');
            const year      = escapeHTML(b.year      || '');
            const publisher = escapeHTML(b.publisher || '');
            const imgUrl    = escapeHTML(b.cover     || '');
            const desc      = escapeHTML(b.description || '');
            const isbn      = escapeHTML(b.isbn      || '');
            const link      = escapeHTML(b.source_link || '');
            const rawKey    = b.key || '';
            const olid      = escapeHTML(rawKey.startsWith('/works/') ? rawKey.slice(7) : '');
            const subjects  = (b.subjects || []).slice(0, 8).map(s => `<span class="badge bg-secondary me-1">${escapeHTML(s)}</span>`).join('');

            return `<div class="mb-3 p-3 border rounded">
              <div class="d-flex gap-3">
                ${imgUrl ? `<img src="${imgUrl}" style="height:120px;width:auto;object-fit:cover" class="flex-shrink-0">` : ''}
                <div class="flex-grow-1">
                  <div class="fw-semibold">${title}${link ? ` <a href="${link}" target="_blank" rel="noopener" class="ms-1 small text-muted"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>` : ''}</div>
                  ${author    ? `<div class="text-muted small">${author}</div>` : ''}
                  ${year || publisher ? `<div class="text-muted small">${[publisher, year].filter(Boolean).join(' · ')}</div>` : ''}
                  ${isbn      ? `<div class="text-muted small">ISBN: ${isbn}</div>` : ''}
                  ${olid      ? `<div class="text-muted small">OLID: ${olid}</div>` : ''}
                  ${desc      ? `<div class="mt-2 small">${desc}</div>` : ''}
                  ${subjects  ? `<div class="mt-2">${subjects}</div>` : ''}
                  <div class="mt-2 d-flex gap-2 flex-wrap">
                    ${imgUrl  ? `<button type="button" class="btn btn-sm btn-outline-primary openlibrary-use" data-book-id="${bookId}" data-title="" data-authors="" data-year="${year}" data-imgurl="${imgUrl}" data-description="" data-publisher="${publisher}" data-isbn="${isbn}" data-olid="${olid}"><i class="fa-solid fa-image me-1"></i>Use Cover</button>` : ''}
                    ${desc    ? `<button type="button" class="btn btn-sm btn-outline-secondary openlibrary-use" data-book-id="${bookId}" data-title="" data-authors="" data-year="${year}" data-imgurl="" data-description="${escapeHTML(b.description||'')}" data-publisher="${publisher}" data-isbn="${isbn}" data-olid="${olid}"><i class="fa-solid fa-align-left me-1"></i>Use Description</button>` : ''}
                    ${imgUrl && desc ? `<button type="button" class="btn btn-sm btn-outline-success openlibrary-use" data-book-id="${bookId}" data-title="" data-authors="" data-year="${year}" data-imgurl="${imgUrl}" data-description="${escapeHTML(b.description||'')}" data-publisher="${publisher}" data-isbn="${isbn}" data-olid="${olid}"><i class="fa-solid fa-circle-check me-1"></i>Use Both</button>` : ''}
                    ${isbn    ? `<button type="button" class="btn btn-sm btn-outline-secondary openlibrary-use" data-book-id="${bookId}" data-title="" data-authors="" data-year="${year}" data-imgurl="" data-description="" data-publisher="${publisher}" data-isbn="${isbn}" data-olid="${olid}"><i class="fa-solid fa-barcode me-1"></i>Use ISBN</button>` : ''}
                  </div>
                </div>
              </div>
            </div>`;
          }).join('');
          if (resultsEl) resultsEl.innerHTML = html;
        })
        .catch(() => {
          if (resultsEl) resultsEl.textContent = 'Error fetching results';
        });
      return;
    }
    const useBtn = ev.target.closest('.openlibrary-use');
    if (!useBtn) return;
    const bookId    = useBtn.dataset.bookId;
    const t         = useBtn.dataset.title;
    const a         = useBtn.dataset.authors;
    const y         = useBtn.dataset.year;
    const img       = useBtn.dataset.imgurl;
    const desc      = useBtn.dataset.description;
    const publisher = useBtn.dataset.publisher || '';
    const isbn      = useBtn.dataset.isbn      || '';
    const olid      = useBtn.dataset.olid      || '';
    try {
      const response = await fetch('json_endpoints/update_metadata.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: bookId, title: t, authors: a, year: y, imgurl: img, description: desc, publisher, isbn, olid })
      });
      const data = await response.json();
      if (data.status === 'ok') {
        openLibraryModal.hide();
        const bookBlock = document.querySelector(`[data-book-block-id="${bookId}"]`);
        if (bookBlock) {
          const titleEl = bookBlock.querySelector('.book-title');
          if (titleEl) titleEl.textContent = t;
          const authorsEl = bookBlock.querySelector('.book-authors');
          if (authorsEl) {
            if (data.authors_html) {
              authorsEl.innerHTML = data.authors_html;
            } else {
              authorsEl.textContent = a || '—';
            }
          }
          const descEl = bookBlock.querySelector('.book-description');
          if (descEl) setDescription(descEl, desc);
          const coverSrc = data.cover_url || img;
          if (coverSrc) {
            const imgElem = bookBlock.querySelector('.book-cover');
            if (imgElem) {
              imgElem.src = `${coverSrc}?t=${Date.now()}`;
              initCoverDimensions(bookBlock);
            } else {
              const wrapper = bookBlock.querySelector('.cover-wrapper');
              if (wrapper) {
                wrapper.innerHTML = `<div class="position-relative d-inline-block"><img src="${coverSrc}" class="img-thumbnail img-fluid book-cover" alt="Cover" style="width: 100%; max-width:150px; height:auto;"><div class="cover-dimensions position-absolute bottom-0 end-0 bg-dark text-white px-2 py-1 small rounded-top-start opacity-75" style="font-size: 0.8rem;">Loading...</div></div>`;
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

  // Recommendations modal — renderRecs, checkRecsInLibrary, recsSpinner from js/recommendations.js
  let _recLink = null;

  function openRecModal(link) {
    _recLink = link;
    const recText = link.dataset.recText || '';
    document.getElementById('recModalLabel').textContent = link.dataset.title || 'Recommendations';
    document.getElementById('recModalStatus').textContent = '';

    const content    = document.getElementById('recModalContent');
    const genBtn     = document.getElementById('recModalGenerate');
    const regenBtn   = document.getElementById('recModalRegenerate');

    if (recText) {
      content.innerHTML = renderRecs(recText);
      checkRecsInLibrary(content);
      genBtn.classList.add('d-none');
      regenBtn.classList.remove('d-none');
    } else {
      content.innerHTML = '<p class="text-muted fst-italic">No recommendations yet.</p>';
      genBtn.classList.remove('d-none');
      regenBtn.classList.add('d-none');
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('recModal')).show();
  }

  function fetchRecommendations() {
    if (!_recLink) return;
    const status  = document.getElementById('recModalStatus');
    const content = document.getElementById('recModalContent');
    const genBtn  = document.getElementById('recModalGenerate');
    const regenBtn = document.getElementById('recModalRegenerate');
    const bookId  = _recLink.dataset.bookId;
    const title   = _recLink.dataset.title   || '';
    const authors = _recLink.dataset.authors || '';
    const genres  = _recLink.dataset.genres  || '';

    status.textContent = 'Generating…';
    content.innerHTML = recsSpinner;
    genBtn.disabled = true;
    regenBtn.disabled = true;

    fetch('json_endpoints/recommend.php?book_id=' + encodeURIComponent(bookId) +
          '&title='   + encodeURIComponent(title) +
          '&authors=' + encodeURIComponent(authors) +
          '&genres='  + encodeURIComponent(genres))
      .then(r => r.json())
      .then(data => {
        genBtn.disabled = false;
        regenBtn.disabled = false;
        if (data.output) {
          status.textContent = 'Saved.';
          content.innerHTML = renderRecs(data.output);
          checkRecsInLibrary(content);
          genBtn.classList.add('d-none');
          regenBtn.classList.remove('d-none');
          _recLink.dataset.recText = data.output;
          if (!_recLink.querySelector('.fa-star')) {
            _recLink.insertAdjacentHTML('afterbegin', '<i class="fa-solid fa-star text-warning me-1"></i>');
          }
        } else {
          status.textContent = 'Error: ' + (data.error || 'failed');
        }
      })
      .catch(() => {
        genBtn.disabled = false;
        regenBtn.disabled = false;
        status.textContent = 'Network error.';
      });
  }

  document.getElementById('recModalGenerate')?.addEventListener('click', fetchRecommendations);
  document.getElementById('recModalRegenerate')?.addEventListener('click', fetchRecommendations);

  // Description modal
  let _descBookId = null;
  let _descBox    = null;

  function openDescModal(box) {
    _descBookId = box.dataset.bookId;
    _descBox    = box;
    const html  = box.dataset.html || '';
    const title = box.closest('[data-book-block-id]')?.querySelector('[data-book-id]')?.textContent?.trim() || 'Description';
    document.getElementById('descModalLabel').textContent = title;
    document.getElementById('descModalStatus').textContent = '';

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('descModal'));

    if (typeof tinymce !== 'undefined') {
      tinymce.remove('#descModalEditor');
      tinymce.init({
        selector: '#descModalEditor',
        plugins: 'lists link',
        toolbar: 'bold italic underline | bullist numlist | link | removeformat',
        menubar: false,
        height: 418,
        resize: false,
        skin_url: 'https://cdn.jsdelivr.net/npm/tinymce@6/skins/ui/oxide',
        content_css: 'https://cdn.jsdelivr.net/npm/tinymce@6/skins/content/default/content.min.css',
        setup(ed) {
          ed.on('init', () => {
            ed.setContent(html);
            modal.show();
          });
        }
      });
    } else {
      document.getElementById('descModalEditor').value = html;
      modal.show();
    }
  }

  document.getElementById('descModalSave')?.addEventListener('click', () => {
    if (!_descBookId) return;
    const status = document.getElementById('descModalStatus');
    const content = typeof tinymce !== 'undefined' && tinymce.get('descModalEditor')
      ? tinymce.get('descModalEditor').getContent()
      : document.getElementById('descModalEditor').value;

    status.textContent = 'Saving…';
    const fd = new FormData();
    fd.append('book_id', _descBookId);
    fd.append('description', content);
    fetch('json_endpoints/update_metadata.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.success || data.status === 'ok') {
          status.textContent = 'Saved.';
          // Update data attributes and preview on the row
          if (_descBox) {
            const plain = content.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            _descBox.dataset.html = content;
            _descBox.dataset.full = plain;
            let preview = plain.slice(0, 300).replace(/\s+\S*$/, '');
            if (preview.length < plain.length) preview += '…';
            _descBox.innerHTML = (preview ? preview + ' ' : '') + '<a href="#" class="desc-edit">Edit</a>';
          }
          setTimeout(() => bootstrap.Modal.getOrCreateInstance(document.getElementById('descModal'))?.hide(), 800);
        } else {
          status.textContent = 'Error: ' + (data.error || 'save failed');
        }
      })
      .catch(() => { status.textContent = 'Network error.'; });
  });

  document.getElementById('descModalSynopsis')?.addEventListener('click', () => {
    if (!_descBookId || !_descBox) return;
    const status  = document.getElementById('descModalStatus');
    const title   = _descBox.dataset.title   || '';
    const authors = _descBox.dataset.authors || '';
    status.textContent = 'Generating…';
    fetch('json_endpoints/synopsis.php?book_id=' + encodeURIComponent(_descBookId) +
          '&title='   + encodeURIComponent(title) +
          '&authors=' + encodeURIComponent(authors))
      .then(r => r.json())
      .then(data => {
        const text = data.output || data.error || 'Error';
        status.textContent = '';
        const ed = typeof tinymce !== 'undefined' ? tinymce.get('descModalEditor') : null;
        if (ed) { ed.setContent(text); } else { document.getElementById('descModalEditor').value = text; }
      })
      .catch(() => { status.textContent = 'Network error.'; });
  });

  // Author info modal handler
  document.addEventListener('click', e => {
    const btn = e.target.closest('.author-info-btn');
    if (!btn || !authorModal) return;
    e.preventDefault();

    const authorId   = btn.dataset.authorId;
    const authorName = btn.dataset.authorName;
    _currentAuthorId = authorId;
    if (authorBioStatus)  authorBioStatus.textContent = '';
    if (authorSaveBioBtn) authorSaveBioBtn.classList.add('d-none');

    if (authorModalLabel) authorModalLabel.textContent = authorName;
    if (authorFilterLink) {
      const params = new URLSearchParams(window.location.search);
      params.set('author_id', authorId);
      params.delete('page');
      authorFilterLink.href = 'list_books.php?' + params.toString();
    }
    if (authorModalBody) authorModalBody.innerHTML = `<div class="d-flex justify-content-center py-4">
      <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading…</span></div>
    </div>`;
    authorModal.show();

    fetch('json_endpoints/author_info.php?author_id=' + encodeURIComponent(authorId))
      .then(r => r.json())
      .then(data => {
        if (data.error) { authorModalBody.innerHTML = `<p class="text-danger">${escapeHTML(data.error)}</p>`; return; }

        const ids = data.identifiers || {};
        const olaid     = ids.olaid     || '';
        const goodreads = ids.goodreads || '';
        const wikidata  = ids.wikidata  || '';

        let html = '<div class="row g-3">';

        // Photo
        if (data.photo) {
          html += `<div class="col-auto">
            <img src="${escapeHTML(data.photo)}" class="img-thumbnail" style="max-height:180px;width:auto">
          </div>`;
        }

        // Main info
        html += '<div class="col">';
        html += `<h5 class="mb-1">${escapeHTML(data.name)}</h5>`;
        html += `<div class="text-muted small mb-3">${escapeHTML(data.book_count)} book${data.book_count !== 1 ? 's' : ''} in library</div>`;

        // External links
        const extLinks = [];
        if (olaid)     extLinks.push(`<a href="https://openlibrary.org/authors/${encodeURIComponent(olaid)}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary"><i class="fa-solid fa-book-open me-1"></i>Open Library</a>`);
        if (goodreads) extLinks.push(`<a href="https://www.goodreads.com/author/show/${encodeURIComponent(goodreads)}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary"><i class="fa-solid fa-star me-1"></i>Goodreads</a>`);
        if (wikidata)  extLinks.push(`<a href="https://www.wikidata.org/wiki/${encodeURIComponent(wikidata)}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary"><i class="fa-brands fa-wikipedia-w me-1"></i>Wikidata</a>`);
        if (extLinks.length) html += `<div class="d-flex flex-wrap gap-2 mb-3">${extLinks.join('')}</div>`;

        // Identifiers table
        if (olaid || goodreads || wikidata) {
          html += '<table class="table table-sm table-borderless small mb-3" style="width:auto">';
          if (olaid)     html += `<tr><td class="text-muted pe-3">OL Author ID</td><td><code>${escapeHTML(olaid)}</code></td></tr>`;
          if (goodreads) html += `<tr><td class="text-muted pe-3">Goodreads ID</td><td><code>${escapeHTML(goodreads)}</code></td></tr>`;
          if (wikidata)  html += `<tr><td class="text-muted pe-3">Wikidata</td><td><code>${escapeHTML(wikidata)}</code></td></tr>`;
          html += '</table>';
        }

        html += '</div></div>'; // close col + row

        // Bio — always show editable textarea
        html += `<hr><label class="form-label small text-muted mb-1">Bio</label>
          <textarea id="authorBioTextarea" class="form-control small" rows="12" placeholder="No bio available — type here to add one">${escapeHTML(data.bio || '')}</textarea>`;

        if (!olaid && !goodreads && !wikidata && !data.bio && !data.photo) {
          html += '<p class="text-muted small mt-2">No additional information found for this author.</p>';
        }

        authorModalBody.innerHTML = html;
        if (authorSaveBioBtn) authorSaveBioBtn.classList.remove('d-none');
      })
      .catch(() => { authorModalBody.innerHTML = '<p class="text-danger">Failed to load author info.</p>'; });
  });

  document.addEventListener('click', e => {
    const more = e.target.closest('.desc-edit');
    if (more) {
      e.preventDefault();
      const box = more.closest('.book-description');
      if (box) openDescModal(box);
      return;
    }

    const recLink = e.target.closest('.rec-link');
    if (recLink) {
      e.preventDefault();
      openRecModal(recLink);
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

  // ── Bulk selection (simple view) ────────────────────────────────
  const bulkSelectAll          = document.getElementById('bulkSelectAll');
  const bulkSelectNotOnDevice  = document.getElementById('bulkSelectNotOnDevice');
  const bulkSendBtn            = document.getElementById('bulkSendBtn');
  const bulkDeleteBtn          = document.getElementById('bulkDeleteBtn');
  const bulkRemoveDevBtn       = document.getElementById('bulkRemoveDevBtn');
  const bulkTransferBtn        = document.getElementById('bulkTransferBtn');
  const bulkTransferTarget     = document.getElementById('bulkTransferTarget');
  const bulkStatus             = document.getElementById('bulkStatus');

  function getChecked() {
    return Array.from(document.querySelectorAll('.bulk-select:checked'));
  }

  function updateBulkButtons() {
    const checked = getChecked();
    const count = checked.length;
    if (bulkSendBtn)     bulkSendBtn.disabled     = count === 0;
    if (bulkDeleteBtn)   bulkDeleteBtn.disabled    = count === 0;
    if (bulkTransferBtn) bulkTransferBtn.disabled  = count === 0;
    if (bulkStatus)      bulkStatus.textContent    = count > 0 ? count + ' selected' : '';
    if (bulkSelectAll) {
      const all = document.querySelectorAll('.bulk-select');
      bulkSelectAll.checked       = all.length > 0 && count === all.length;
      bulkSelectAll.indeterminate = count > 0 && count < all.length;
    }
    // Enable remove-from-device only when at least one checked row has a device path
    if (bulkRemoveDevBtn) {
      const hasOnDevice = checked.some(cb => {
        const row = cb.closest('[data-book-block-id]');
        return row && row.querySelector('.remove-from-device-row');
      });
      bulkRemoveDevBtn.disabled = !hasOnDevice;
    }
    // Sync selected-row highlight class
    document.querySelectorAll('.bulk-select').forEach(cb => {
      cb.closest('[data-book-block-id]')?.classList.toggle('row-selected', cb.checked);
    });
  }

  document.addEventListener('click', e => {
    const row = e.target.closest('.simple-row[data-book-block-id]');
    if (!row) return;
    const cb = row.querySelector('.bulk-select');
    if (!cb) return;
    // Direct checkbox click — browser already toggled it, just sync UI
    if (e.target === cb) {
      requestAnimationFrame(updateBulkButtons);
      return;
    }
    // If click landed on any other interactive element, leave it alone
    if (e.target.closest('a, button, select, input, label')) return;
    // Dead area click — toggle the checkbox ourselves
    cb.checked = !cb.checked;
    requestAnimationFrame(updateBulkButtons);
  });

  if (bulkSelectAll) {
    bulkSelectAll.addEventListener('change', () => {
      document.querySelectorAll('.bulk-select').forEach(cb => { cb.checked = bulkSelectAll.checked; });
      updateBulkButtons();
    });
  }

  if (bulkSelectNotOnDevice) {
    bulkSelectNotOnDevice.addEventListener('click', () => {
      // Rows without a remove button = not on device
      const notOnDevice = Array.from(document.querySelectorAll('.simple-row[data-book-block-id]'))
        .filter(row => !row.querySelector('.remove-from-device-row'));
      const allChecked = notOnDevice.every(row => row.querySelector('.bulk-select')?.checked);
      notOnDevice.forEach(row => {
        const cb = row.querySelector('.bulk-select');
        if (cb) cb.checked = !allChecked;
      });
      updateBulkButtons();
    });
  }

  if (bulkRemoveDevBtn) {
    bulkRemoveDevBtn.addEventListener('click', async () => {
      const checked = getChecked();
      if (!checked.length) return;
      // Collect only checked rows that have a device path
      const toRemove = checked.reduce((acc, cb) => {
        const row = cb.closest('[data-book-block-id]');
        const btn = row && row.querySelector('.remove-from-device-row');
        if (btn && btn.dataset.devicePath) acc.push({ cb, row, btn });
        return acc;
      }, []);
      if (!toRemove.length) return;
      const ok = await showConfirm(
        'Remove from device',
        'Remove ' + toRemove.length + ' book(s) from the device?',
        'warning'
      );
      if (!ok) return;
      bulkRemoveDevBtn.disabled = true;
      if (bulkSendBtn)   bulkSendBtn.disabled   = true;
      if (bulkDeleteBtn) bulkDeleteBtn.disabled  = true;
      if (bulkStatus) bulkStatus.textContent = 'Removing…';
      let done = 0, failed = 0;
      for (const { row, btn } of toRemove) {
        if (bulkStatus) bulkStatus.textContent = 'Removing ' + (done + failed + 1) + ' / ' + toRemove.length + '…';
        try {
          const res  = await fetch('json_endpoints/remove_from_device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ device_path: btn.dataset.devicePath })
          });
          const data = await res.json();
          if (data.status === 'ok') {
            markRowOffDevice(row);
            done++;
          } else {
            failed++;
            console.warn('[bulk-remove]', data.error);
          }
        } catch (err) { failed++; console.error(err); }
      }
      const summary = 'Removed ' + done + ' book(s) from device' + (failed ? ', ' + failed + ' failed.' : '.');
      if (bulkStatus) bulkStatus.textContent = summary;
      showSendResult(failed === 0, summary);
      if (done > 0) fetch('json_endpoints/sync_device.php', { method: 'POST' });
      updateBulkButtons();
    });
  }

  if (bulkDeleteBtn) {
    bulkDeleteBtn.addEventListener('click', async () => {
      const checked = getChecked();
      if (!checked.length) return;
      const ok = await showConfirm(
        'Delete selected books',
        'Permanently delete ' + checked.length + ' book(s)? This cannot be undone.'
      );
      if (!ok) return;
      bulkDeleteBtn.disabled = true;
      bulkSendBtn.disabled   = true;
      if (bulkRemoveDevBtn) bulkRemoveDevBtn.disabled = true;
      if (bulkStatus) bulkStatus.textContent = 'Deleting…';
      let done = 0;
      for (const cb of checked) {
        const bookId = cb.dataset.bookId;
        try {
          const res  = await fetch('json_endpoints/delete_book.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ book_id: bookId })
          });
          const data = await res.json();
          if (data.status === 'ok') {
            cb.closest('[data-book-block-id]')?.remove();
            done++;
          }
        } catch (err) { console.error(err); }
        if (bulkStatus) bulkStatus.textContent = 'Deleted ' + done + ' / ' + checked.length + '…';
      }
      if (bulkStatus) bulkStatus.textContent = 'Deleted ' + done + ' book(s).';
      updateBulkButtons();
    });
  }

  if (bulkSendBtn) {
    bulkSendBtn.addEventListener('click', async () => {
      const checked = getChecked();
      if (!checked.length) return;
      bulkSendBtn.disabled   = true;
      bulkDeleteBtn.disabled = true;
      if (bulkRemoveDevBtn) bulkRemoveDevBtn.disabled = true;
      let done = 0, failed = 0;
      const resultItems = [];
      for (const cb of checked) {
        const bookId = cb.dataset.bookId;
        const row    = cb.closest('[data-book-block-id]');
        const bookTitle = row?.querySelector('.book-title a')?.textContent?.trim() || 'Book ' + bookId;
        if (bulkStatus) bulkStatus.textContent = 'Sending ' + (done + failed + 1) + ' / ' + checked.length + '…';
        // Write metadata first (best-effort)
        try {
          const fd = new FormData();
          fd.append('book_id', bookId);
          await fetch('json_endpoints/write_ebook_metadata.php', { method: 'POST', body: fd });
        } catch (e) { /* ignore */ }
        // Send to device
        try {
          const res  = await fetch('json_endpoints/send_to_device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ book_id: bookId })
          });
          const data = await res.json();
          if (data.status === 'ok') {
            done++;
            resultItems.push({ text: bookTitle, ok: true });
            if (data.device_path) {
              if (row) markRowOnDevice(row, bookId, data.device_path);
            }
          } else {
            failed++;
            resultItems.push({ text: bookTitle, ok: false });
            console.warn('[bulk-send]', data.error, data.detail);
          }
        } catch (err) {
          failed++;
          resultItems.push({ text: bookTitle, ok: false });
          console.error(err);
        }
      }
      const summary = 'Sent ' + done + ' book(s)' + (failed ? ', ' + failed + ' failed.' : '.');
      if (bulkStatus) bulkStatus.textContent = summary;
      showSendResult(failed === 0, summary, '', resultItems);
      if (done > 0) fetch('json_endpoints/sync_device.php', { method: 'POST' }); // background sync
      updateBulkButtons();
    });
  }

  // ── Bulk transfer to library ─────────────────────────────────────
  if (bulkTransferBtn && bulkTransferTarget) {
    bulkTransferBtn.addEventListener('click', async () => {
      const checked    = getChecked();
      if (!checked.length) return;
      const targetUser = bulkTransferTarget.value;
      if (!targetUser) return;

      const ok = await showConfirm(
        'Copy to library',
        'Copy ' + checked.length + ' book(s) to ' + targetUser + '\'s library?'
      );
      if (!ok) return;

      bulkTransferBtn.disabled = true;
      if (bulkSendBtn)     bulkSendBtn.disabled     = true;
      if (bulkDeleteBtn)   bulkDeleteBtn.disabled    = true;
      if (bulkRemoveDevBtn) bulkRemoveDevBtn.disabled = true;

      let done = 0, failed = 0, dupes = 0;
      const resultItems = [];

      for (const cb of checked) {
        const bookId    = cb.dataset.bookId;
        const row       = cb.closest('[data-book-block-id]');
        const bookTitle = row?.querySelector('.book-title a')?.textContent?.trim() || 'Book ' + bookId;
        if (bulkStatus) bulkStatus.textContent = 'Copying ' + (done + failed + dupes + 1) + ' / ' + checked.length + '…';

        try {
          const res  = await fetch('json_endpoints/transfer_book.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ book_id: bookId, target_user: targetUser })
          });
          const data = await res.json();

          if (data.status === 'ok') {
            done++;
            resultItems.push({ text: bookTitle, ok: true });
          } else if (data.status === 'duplicate') {
            dupes++;
            resultItems.push({ text: bookTitle + ' (already in library)', ok: false });
          } else {
            failed++;
            resultItems.push({ text: bookTitle + ': ' + (data.error || 'error'), ok: false });
            console.warn('[bulk-transfer]', data.error);
          }
        } catch (err) {
          failed++;
          resultItems.push({ text: bookTitle, ok: false });
          console.error(err);
        }
      }

      const parts = ['Copied ' + done + ' book(s)'];
      if (dupes)  parts.push(dupes  + ' already in library');
      if (failed) parts.push(failed + ' failed');
      const summary = parts.join(', ') + '.';
      if (bulkStatus) bulkStatus.textContent = summary;
      showSendResult(failed === 0, summary, '', resultItems);
      updateBulkButtons();
    });
  }
});
