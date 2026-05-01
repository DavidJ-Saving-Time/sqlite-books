
function stripWikiRefs(el) {
  el.querySelectorAll(
    'sup.reference, .mw-references-wrap, .reflist, ol.references, .mw-references, table.ambox'
  ).forEach(n => n.remove());
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

history.scrollRestoration = 'manual';

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

  // Wikipedia book modal
  const wikiBookModalEl = document.getElementById('wikiBookModal');
  if (wikiBookModalEl) {
    const wikiBookModal       = new bootstrap.Modal(wikiBookModalEl);
    const wikiBookModalBody   = document.getElementById('wikiBookModalBody');
    const wikiBookModalLabel  = document.getElementById('wikiBookModalLabel');
    const wikiBookModalLink   = document.getElementById('wikiBookModalLink');
    const wikiBookModalStatus = document.getElementById('wikiBookModalStatus');
    const wikiBookRefetchBtn  = document.getElementById('wikiBookModalRefetch');
    let   _wikiCurrentBookId  = null;
    let   _wikiCurrentTitle   = null;

    function wikiShowSpinner() {
      wikiBookModalBody.innerHTML = `
        <div class="d-flex justify-content-center py-4">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading…</span>
          </div>
        </div>`;
      wikiBookModalLink.classList.add('d-none');
      wikiBookRefetchBtn.classList.add('d-none');
      wikiBookModalStatus.textContent = 'Fetching from Wikipedia…';
    }

    function wikiShowData(data) {
      wikiBookModalLabel.textContent = data.title || _wikiCurrentTitle;
      let html = `<div class="wiki-extract">${data.extract}</div>`;
      if (data.plot) {
        html += `<h6 class="mt-3 mb-2 fw-semibold border-top pt-3">Plot</h6><div class="wiki-plot">${data.plot}</div>`;
      }
      wikiBookModalBody.innerHTML    = html;
      stripWikiRefs(wikiBookModalBody);
      wikiBookModalLink.href         = data.url;
      wikiBookModalLink.classList.remove('d-none');
      wikiBookRefetchBtn.classList.remove('d-none');
      wikiBookModalStatus.textContent = '';
    }

    function wikiShowError(msg) {
      wikiBookModalBody.innerHTML     = `<p class="text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>${msg}</p>`;
      wikiBookModalLink.classList.add('d-none');
      wikiBookRefetchBtn.classList.add('d-none');
      wikiBookModalStatus.textContent = '';
    }

    async function wikiFetchLive(bookId) {
      try {
        const fd = new FormData();
        fd.append('book_id', bookId);
        const res  = await fetch('json_endpoints/fetch_wikipedia.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.error) { wikiShowError(data.error); return; }
        const rowBtn = document.querySelector(`.wiki-book-btn[data-book-id="${bookId}"]`);
        if (rowBtn) {
          rowBtn.dataset.wikiCached = '1';
          rowBtn.title              = 'Wikipedia: ' + (data.data.title || _wikiCurrentTitle);
          rowBtn.style.color        = 'var(--accent, #0d6efd)';
          rowBtn.style.opacity      = '1';
        }
        wikiShowData(data.data);
      } catch (err) {
        wikiShowError('Request failed — please try again.');
      }
    }

    async function wikiFetch(bookId) {
      wikiShowSpinner();
      // Try stored data first (fast DB read, no external call)
      try {
        const res  = await fetch(`json_endpoints/wiki_book_read.php?book_id=${encodeURIComponent(bookId)}`);
        const data = await res.json();
        if (data.status === 'ok') { wikiShowData(data.data); return; }
      } catch { /* fall through to live fetch */ }
      // Not in DB — fetch from Wikipedia and store
      await wikiFetchLive(bookId);
    }

    document.addEventListener('click', e => {
      const btn = e.target.closest('.wiki-book-btn');
      if (!btn) return;
      e.preventDefault();
      _wikiCurrentBookId = btn.dataset.bookId;
      _wikiCurrentTitle  = btn.dataset.bookTitle;
      wikiBookModalLabel.textContent = _wikiCurrentTitle;
      wikiBookModal.show();
      wikiFetch(_wikiCurrentBookId);
    });

    wikiBookRefetchBtn.addEventListener('click', () => {
      if (_wikiCurrentBookId) { wikiShowSpinner(); wikiFetchLive(_wikiCurrentBookId); }
    });
  }

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
  let   confirmOkBtn    = document.getElementById('confirmModalOk');

  /** Mark a book row as now on-device: swap send button → remove button, add title icon. */
  function markRowOnDevice(row, bookId, devicePath) {
    // Add tablet icon after book title link if not already there
    const titleSpan = row.querySelector('.book-title');
    if (titleSpan && !titleSpan.querySelector('.fa-tablet-screen-button')) {
      const icon = document.createElement('i');
      icon.className = 'fa-solid fa-tablet-screen-button text-success ms-1';
      icon.style.fontSize = '0.75rem';
      icon.title = 'On device';
      titleSpan.appendChild(icon);
    }
    // Swap send button → remove button
    const sendBtn = row.querySelector('.send-to-device-row');
    if (sendBtn) {
      const hidden = !!sendBtn.closest('[aria-hidden="true"]');
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = hidden
        ? 'remove-from-device-row'
        : 'btn btn-sm btn-outline-warning remove-from-device-row py-0 px-2';
      removeBtn.dataset.bookId = bookId;
      removeBtn.dataset.devicePath = devicePath;
      if (!hidden) {
        removeBtn.title = 'Remove from device';
        removeBtn.innerHTML = '<i class="fa-solid fa-tablet-screen-button"></i>';
      }
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
      const hidden = !!removeBtn.closest('[aria-hidden="true"]');
      const sendBtn = document.createElement('button');
      sendBtn.type = 'button';
      sendBtn.className = hidden
        ? 'send-to-device-row'
        : 'btn btn-sm btn-outline-success send-to-device-row py-0 px-2';
      sendBtn.dataset.bookId = bookId;
      if (!hidden) {
        sendBtn.title = 'Send to device';
        sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
      }
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
      // Replace confirm button to wipe old listeners; keep reference in sync
      const fresh = confirmOkBtn.cloneNode(true);
      confirmOkBtn.replaceWith(fresh);
      confirmOkBtn = fresh;
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
      .then(res => { if (!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
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

  let skipLoad = false;

  async function loadNext() {
    if (highestPage >= totalPages || isLoading || skipLoad) return;
    isLoading = true;
    const p = highestPage + 1;
    let prom = nextCache.get(p);
    if (!prom) { prom = fetchPage(p); nextCache.set(p, prom); }
    await showSpinner();
    try {
      const els = await prom;
      if (p !== highestPage + 1) { nextCache.delete(p); return; }   // stale — another load beat us
      nextCache.delete(p);
      els.forEach(el => bottomSentinel.parentNode.insertBefore(el, bottomSentinel));
      initCoverDimensions(els);
      initSimilarPanels(els);
      initTwoDescToggles(els);
      initTwoAutoSections(els);
      initServerStripArrows(els);
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
    if (lowestPage <= 1 || isLoading || skipLoad) return;
    isLoading = true;
    const p = lowestPage - 1;
    let prom = prevCache.get(p);
    if (!prom) { prom = fetchPage(p); prevCache.set(p, prom); }
    await showSpinner();
    try {
      const els = await prom;
      if (p !== lowestPage - 1) { prevCache.delete(p); return; }    // stale
      prevCache.delete(p);
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
      initSimilarPanels(els);
      initTwoDescToggles(els);
      initTwoAutoSections(els);
      initServerStripArrows(els);
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
  // Used by trimPages() to determine which page window to keep in the DOM.
  let firstVisibleIndex = null;
  const visibilityObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      const idx = parseInt(entry.target.dataset.bookIndex, 10);
      if (entry.isIntersecting) {
        if (firstVisibleIndex === null || idx < firstVisibleIndex) firstVisibleIndex = idx;
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
    const navH = document.querySelector('.navbar')?.offsetHeight ?? 56;
    for (const item of contentArea.querySelectorAll('.list-item')) {
      if (item.getBoundingClientRect().bottom > navH) return item.dataset.bookIndex;
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

    if (lowestPage >= minPage && highestPage <= maxPage) return; // nothing to trim

    topObserver.unobserve(topSentinel);
    bottomObserver.unobserve(bottomSentinel);

    // Single DOM query — bucket items by page index so each trim loop
    // doesn't have to re-scan the entire node list.
    const byPage = new Map();
    contentArea.querySelectorAll('.list-item').forEach(item => {
      const i = parseInt(item.dataset.bookIndex, 10);
      const pg = Math.floor(i / perPage) + 1;
      if (!byPage.has(pg)) byPage.set(pg, []);
      byPage.get(pg).push(item);
    });

    while (lowestPage < minPage) {
      const items = byPage.get(lowestPage) ?? [];
      // Read all heights in one batch before any removal to avoid per-item reflow
      const h = items.reduce((sum, item) => sum + item.getBoundingClientRect().height, 0);
      items.forEach(item => { visibilityObserver.unobserve(item); item.remove(); });
      trimmedTopHeights.push(h);
      lowestPage++;
    }

    while (highestPage > maxPage) {
      const items = byPage.get(highestPage) ?? [];
      const h = items.reduce((sum, item) => sum + item.getBoundingClientRect().height, 0);
      items.forEach(item => { visibilityObserver.unobserve(item); item.remove(); });
      trimmedBottomHeights.push(h);
      highestPage--;
    }

    updateSpacers();

    bottomObserver.observe(bottomSentinel);
    topObserver.observe(topSentinel);

    // If a sentinel is already in the viewport after trimming, the observer won't
    // fire (no state change on re-observe). Check manually and trigger the load.
    if (bottomSentinel.getBoundingClientRect().top < window.innerHeight) loadNext();
    if (topSentinel.getBoundingClientRect().bottom > 0) loadPrevious();

    prefetchNext();
    prefetchPrevious();
  }

  // ── State save: URL bar ────────────────────────────────────────────────────
  // Uses a synchronous getBoundingClientRect scan so there are no async observer
  // race conditions — whatever is in the DOM at call time is what gets saved.
  function firstVisibleBookId() {
    const navH = (document.querySelector('.navbar')?.offsetHeight ?? 56) + 8;
    for (const item of contentArea.querySelectorAll('.list-item')) {
      if (item.getBoundingClientRect().bottom > navH) {
        return item.dataset.bookBlockId ?? null;
      }
    }
    return null;
  }

  function saveState() {
    if (skipSave) return;
    const bookId = firstVisibleBookId();
    if (!bookId) return;
    const el = contentArea.querySelector('[data-book-block-id="' + bookId + '"]');
    const idx = el ? parseInt(el.dataset.bookIndex, 10) : 0;
    const visiblePage = Math.floor(idx / perPage) + 1;
    const url = new URL(window.location.href);
    url.searchParams.set('page', visiblePage);
    url.searchParams.set('book', bookId);
    url.searchParams.delete('item');
    history.replaceState(null, '', url.toString());
  }

  // ── Restore scroll position from book= URL param ───────────────────────────
  // skipLoad prevents loadPrevious/loadNext from firing while we restore —
  // the topSentinel is visible at scroll=0 on load and would otherwise insert
  // page 1 above us, shifting the viewport after we land on the correct book.
  const bookParam = new URL(window.location.href).searchParams.get('book');
  if (bookParam !== null) {
    skipSave = true;
    skipLoad = true;
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        // Direct lookup first; fall back to series-sibling search for cards view
        // where suppressed books aren't in the DOM as their own elements.
        let el = document.querySelector('[data-book-block-id="' + bookParam + '"]');
        if (!el) {
          const needle = ',' + bookParam + ',';
          for (const item of contentArea.querySelectorAll('[data-series-books]')) {
            if ((',' + item.dataset.seriesBooks + ',').includes(needle)) {
              el = item;
              break;
            }
          }
        }
        if (!el) {
          // Still not found — series rep is on a different page, or not a series book.
          // Stay on the requested page: release skipSave but hold skipLoad until the
          // topSentinel scrolls out of view so loadPrevious can't pull in page 1.
          // Hard deadline of 3 s so skipLoad never stays locked if the user doesn't scroll.
          skipSave = false;
          const releaseDeadline = Date.now() + 3000;
          const releaseSentinel = () => {
            if (lowestPage <= 1 || topSentinel.getBoundingClientRect().bottom <= 0 || Date.now() >= releaseDeadline) {
              skipLoad = false;
            } else {
              requestAnimationFrame(releaseSentinel);
            }
          };
          requestAnimationFrame(releaseSentinel);
          return;
        }

        const navH    = document.querySelector('.navbar')?.offsetHeight ?? 56;
        const pinTop  = navH + 8;   // desired viewport-top offset for the target element

        const scrollToEl = () => {
          const top = el.getBoundingClientRect().top + window.scrollY - pinTop;
          window.scrollTo({ top, behavior: 'instant' });
        };

        // Show spinner so the user sees a brief loading indicator instead of
        // watching the page micro-adjust as layout shifts settle.
        if (loadingSpinner) loadingSpinner.classList.remove('d-none');

        scrollToEl();

        // Actively hold position each frame so layout shifts from lazy-loading
        // content (similar books, images, page loads) don't drift us away.
        // Bail out immediately if the user touches wheel/keyboard/touch.
        let holding = true;
        let lastInput = 0;
        const onInput = () => { lastInput = Date.now(); };
        window.addEventListener('wheel',     onInput, { passive: true });
        window.addEventListener('touchmove', onInput, { passive: true });
        window.addEventListener('keydown',   onInput);

        const hold = () => {
          if (!holding) return;
          // User scrolled within last 150 ms → hand control back
          if (Date.now() - lastInput < 150) {
            holding = false;
            cleanup();
            return;
          }
          // Element removed from DOM by trimPages — abort to avoid locking at position 0
          if (!el.isConnected) { holding = false; cleanup(); return; }
          const drift = Math.abs(el.getBoundingClientRect().top - pinTop);
          if (drift > 2) scrollToEl();
          requestAnimationFrame(hold);
        };

        let cleaned = false;
        const cleanup = () => {
          if (cleaned) return;
          cleaned = true;
          window.removeEventListener('wheel',     onInput);
          window.removeEventListener('touchmove', onInput);
          window.removeEventListener('keydown',   onInput);
          skipSave = false;
          skipLoad = false;
          if (loadingSpinner) loadingSpinner.classList.add('d-none');
        };

        requestAnimationFrame(hold);

        // Release when layout settles: no contentArea resize for 200 ms.
        // Falls back to a 3 s hard deadline if ResizeObserver never fires or keeps firing.
        let debounceTimer = null;
        const hardDeadline = setTimeout(() => {
          ro.disconnect();
          holding = false;
          cleanup();
        }, 3000);

        const ro = new ResizeObserver(() => {
          clearTimeout(debounceTimer);
          debounceTimer = setTimeout(() => {
            ro.disconnect();
            clearTimeout(hardDeadline);
            holding = false;
            cleanup();
          }, 400);
        });
        ro.observe(contentArea);
      });
    });
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

  document.addEventListener('mousedown', e => {
    const sel = e.target.closest('.genre-select');
    if (!sel || sel.dataset.populated) return;
    sel.dataset.populated = '1';
    const current = sel.dataset.current || '';
    sel.innerHTML = '<option value="">—</option>';
    (window.genreOptions || []).forEach(g => {
      const opt = document.createElement('option');
      opt.value = g;
      opt.textContent = g;
      if (g === current) opt.selected = true;
      sel.appendChild(opt);
    });
  });

  document.addEventListener('mousedown', e => {
    const sel = e.target.closest('.shelf-select');
    if (!sel || sel.dataset.populated) return;
    sel.dataset.populated = '1';
    const current = sel.dataset.current || '';
    sel.innerHTML = '<option value="">—</option>';
    (window.shelfOptions || []).forEach(s => {
      const opt = document.createElement('option');
      opt.value = s;
      opt.textContent = s;
      if (s === current) opt.selected = true;
      sel.appendChild(opt);
    });
  });

  document.addEventListener('mousedown', e => {
    const sel = e.target.closest('.status-select');
    if (!sel || sel.dataset.populated) return;
    sel.dataset.populated = '1';
    const current = sel.dataset.current || 'Want to Read';
    sel.innerHTML = '';
    const allStatuses = (window.statusOptions || []);
    const opts = allStatuses.includes('Want to Read') ? allStatuses : ['Want to Read', ...allStatuses];
    opts.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s;
      opt.textContent = s;
      if (s === current) opt.selected = true;
      sel.appendChild(opt);
    });
  });

  async function applyGenreFromRow(row, value) {
    if (!row) return;
    const bookId = row.dataset.bookBlockId;
    const allAuthorsBox = row.querySelector('.genre-all-authors');
    const params = { book_id: bookId, value };
    let applyAuthorIds = [];
    if (allAuthorsBox && allAuthorsBox.checked) {
      applyAuthorIds = (row.dataset.authorIds || '').split('|').filter(Boolean);
      if (applyAuthorIds.length) params.author_ids = applyAuthorIds.join(',');
    }
    await fetch('json_endpoints/update_genre.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(params)
    });
    // Update genre dropdowns of other visible rows sharing any of the same authors
    if (applyAuthorIds.length) {
      document.querySelectorAll('[data-book-block-id]').forEach(otherRow => {
        if (otherRow === row) return;
        const otherIds = (otherRow.dataset.authorIds || '').split('|').filter(Boolean);
        if (!otherIds.some(id => applyAuthorIds.includes(id))) return;
        const sel = otherRow.querySelector('.genre-select');
        if (!sel) return;
        let opt = Array.from(sel.options).find(o => o.value === value);
        if (!opt && value !== '') { opt = new Option(value, value); sel.add(opt); }
        sel.value = value;
        sel.dataset.current = value;
      });
    }
  }

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
      const row = e.target.closest('[data-book-block-id]');
      await applyGenreFromRow(row, e.target.value);
    } else if (e.target.classList.contains('genre-all-authors') && e.target.checked) {
      const row = e.target.closest('[data-book-block-id]');
      const sel = row ? row.querySelector('.genre-select') : null;
      if (sel) await applyGenreFromRow(row, sel.value);
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

    const originalHtml = useBtn.innerHTML;
    useBtn.disabled  = true;
    useBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving…';

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
        useBtn.disabled  = false;
        useBtn.innerHTML = originalHtml;
        alert(data.error || 'Error updating metadata');
      }
    } catch (error) {
      console.error(error);
      useBtn.disabled  = false;
      useBtn.innerHTML = originalHtml;
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
    // Capture by value so a quick modal switch can't redirect the write-back
    const link    = _recLink;
    const status  = document.getElementById('recModalStatus');
    const content = document.getElementById('recModalContent');
    const genBtn  = document.getElementById('recModalGenerate');
    const regenBtn = document.getElementById('recModalRegenerate');
    const bookId  = link.dataset.bookId;
    const title   = link.dataset.title   || '';
    const authors = link.dataset.authors || '';
    const genres  = link.dataset.genres  || '';

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
          link.dataset.recText = data.output;
          if (!link.querySelector('.fa-star')) {
            link.insertAdjacentHTML('afterbegin', '<i class="fa-solid fa-star text-warning me-1"></i>');
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
    // Capture by value so a quick modal switch can't redirect the write-back
    const bookId  = _descBookId;
    const descBox = _descBox;
    const status  = document.getElementById('descModalStatus');
    const content = typeof tinymce !== 'undefined' && tinymce.get('descModalEditor')
      ? tinymce.get('descModalEditor').getContent()
      : document.getElementById('descModalEditor').value;

    status.textContent = 'Saving…';
    const fd = new FormData();
    fd.append('book_id', bookId);
    fd.append('description', content);
    fetch('json_endpoints/update_metadata.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.success || data.status === 'ok') {
          status.textContent = 'Saved.';
          if (descBox) {
            const plain = content.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            descBox.dataset.html = content;
            descBox.dataset.full = plain;
            let preview = plain.slice(0, 300).replace(/\s+\S*$/, '');
            if (preview.length < plain.length) preview += '…';
            descBox.innerHTML = (preview ? preview + ' ' : '') + '<a href="#" class="desc-edit">Edit</a>';
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
    const bookId  = _descBookId;
    const descBox = _descBox;
    const status  = document.getElementById('descModalStatus');
    const title   = descBox.dataset.title   || '';
    const authors = descBox.dataset.authors || '';
    status.textContent = 'Generating…';
    fetch('json_endpoints/synopsis.php?book_id=' + encodeURIComponent(bookId) +
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
    e.stopPropagation(); // prevent editable-cell from activating

    const authorId   = btn.dataset.authorId;
    const authorName = btn.dataset.authorName;
    const readonly   = !!btn.dataset.readonly;
    _currentAuthorId = readonly ? null : authorId;
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
        const olaid        = ids.olaid        || '';
        const goodreads    = ids.goodreads    || '';
        const wikidata     = ids.wikidata     || '';
        const storygraph   = ids.storygraph   || '';
        const imdb         = ids.imdb         || '';
        const librarything = ids.librarything || '';

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

        // Birth / death / alternate names
        if (data.birth_date || data.death_date || (data.alt_names && data.alt_names.length)) {
          html += '<dl class="row small mb-2">';
          if (data.birth_date) html += `<dt class="col-sm-4 text-muted">Born</dt><dd class="col-sm-8">${escapeHTML(data.birth_date)}</dd>`;
          if (data.death_date) html += `<dt class="col-sm-4 text-muted">Died</dt><dd class="col-sm-8">${escapeHTML(data.death_date)}</dd>`;
          if (data.alt_names && data.alt_names.length) html += `<dt class="col-sm-4 text-muted">Also known as</dt><dd class="col-sm-8">${escapeHTML(data.alt_names.join(', '))}</dd>`;
          html += '</dl>';
        }

        // External links
        const extLinks = [];
        if (olaid)        extLinks.push(`<a href="https://openlibrary.org/authors/${encodeURIComponent(olaid)}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary"><i class="fa-solid fa-book-open me-1"></i>Open Library</a>`);
        if (goodreads)    extLinks.push(`<a href="https://www.goodreads.com/author/show/${encodeURIComponent(goodreads)}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary"><i class="fa-solid fa-star me-1"></i>Goodreads</a>`);
        if (storygraph)   extLinks.push(`<a href="https://app.thestorygraph.com/authors/${encodeURIComponent(storygraph)}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>StoryGraph</a>`);
        if (imdb)         extLinks.push(`<a href="https://www.imdb.com/name/${encodeURIComponent(imdb)}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>IMDb</a>`);
        if (librarything) extLinks.push(`<a href="https://www.librarything.com/author/${encodeURIComponent(librarything)}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>LibraryThing</a>`);
        if (wikidata)     extLinks.push(`<a href="https://www.wikidata.org/wiki/${encodeURIComponent(wikidata)}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary"><i class="fa-brands fa-wikipedia-w me-1"></i>Wikidata</a>`);
        if (data.links)   data.links.forEach(l => extLinks.push(`<a href="${escapeHTML(l.url)}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>${escapeHTML(l.title)}</a>`));
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

        // Bio
        if (readonly) {
          if (data.bio) {
            html += `<hr><div class="small text-muted mb-1">Bio</div>
              <div class="small" style="white-space:pre-wrap">${escapeHTML(data.bio)}</div>`;
          }
        } else {
          html += `<hr><label class="form-label small text-muted mb-1">Bio</label>
            <textarea id="authorBioTextarea" class="form-control small" rows="12" placeholder="No bio available — type here to add one">${escapeHTML(data.bio || '')}</textarea>`;
        }

        // Wikipedia section
        if (data.wiki_bio) {
          const wikiLink = data.wiki_url
            ? ` <a href="${escapeHTML(data.wiki_url)}" target="_blank" rel="noopener" class="small ms-2"><i class="fa-solid fa-arrow-up-right-from-square"></i> Full article</a>`
            : '';
          html += `<hr><h6 class="fw-semibold mb-2">Wikipedia${wikiLink}</h6><div class="wiki-author-extract">${data.wiki_bio}</div>`;
        }

        // Works list
        if (data.works && data.works.length) {
          html += `<hr><h6 class="fw-semibold mb-2"><i class="fa-solid fa-book me-1 text-primary"></i>Works on Open Library</h6>`;
          html += '<ul class="list-unstyled small mb-0" style="columns:2;gap:1rem">';
          data.works.forEach(w => { html += `<li class="text-muted">${escapeHTML(w)}</li>`; });
          html += '</ul>';
        }

        if (!olaid && !goodreads && !wikidata && !data.bio && !data.photo && !data.wiki_bio) {
          html += '<p class="text-muted small mt-2">No additional information found for this author.</p>';
        }

        authorModalBody.innerHTML = html;
        stripWikiRefs(authorModalBody);
        if (!readonly && authorSaveBioBtn) authorSaveBioBtn.classList.remove('d-none');
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

  // ── View switcher: carry book position across view changes ──────────────────
  // page= is only carried when staying within the same perPage group (list/grid/cards
  // all use perPage=34; simple uses 100). Carrying page across that boundary means
  // the book lands on the wrong page and scroll restoration silently fails.
  document.querySelectorAll('.view-switch-link').forEach(a => {
    a.addEventListener('click', e => {
      e.preventDefault();
      const dest    = new URL(a.href);
      const bookId  = firstVisibleBookId();
      if (bookId) {
        dest.searchParams.set('book', bookId);
        // Use the book's absolute data-book-index divided by the DESTINATION perPage.
        // This maps correctly across all view combinations including simple (100) ↔ others (34).
        const el      = contentArea.querySelector('[data-book-block-id="' + bookId + '"]');
        const idx     = el ? parseInt(el.dataset.bookIndex, 10) : 0;
        const dstPer  = dest.searchParams.get('view') === 'simple' ? 100 : 34;
        dest.searchParams.set('page', String(Math.floor(idx / dstPer) + 1));
      }
      window.location.href = dest.toString();
    });
  });

  // ── Bulk selection (simple view) ────────────────────────────────
  const bulkSelectAll          = document.getElementById('bulkSelectAll');
  const bulkSelectNotOnDevice  = document.getElementById('bulkSelectNotOnDevice');
  const bulkSendBtn            = document.getElementById('bulkSendBtn');
  const bulkDeleteBtn          = document.getElementById('bulkDeleteBtn');
  const bulkRemoveDevBtn       = document.getElementById('bulkRemoveDevBtn');
  const bulkTransferBtn        = document.getElementById('bulkTransferBtn');
  const bulkTransferTarget     = document.getElementById('bulkTransferTarget');
  const bulkResetScraperBtn    = document.getElementById('bulkResetScraperBtn');
  const bulkStatus             = document.getElementById('bulkStatus');

  function getChecked() {
    return Array.from(document.querySelectorAll('.bulk-select:checked'));
  }

  function updateBulkButtons() {
    const checked = getChecked();
    const count = checked.length;
    if (bulkSendBtn)          bulkSendBtn.disabled          = count === 0;
    if (bulkDeleteBtn)        bulkDeleteBtn.disabled         = count === 0;
    if (bulkTransferBtn)      bulkTransferBtn.disabled       = count === 0;
    if (bulkResetScraperBtn)  bulkResetScraperBtn.disabled   = count === 0;
    if (bulkStatus)      bulkStatus.textContent    = count > 0 ? count + ' selected' : '';
    const all = document.querySelectorAll('.bulk-select');
    if (bulkSelectAll) {
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
    // Sync selected-row highlight class (reuse `all` from above)
    all.forEach(cb => {
      cb.closest('[data-book-block-id]')?.classList.toggle('row-selected', cb.checked);
    });
  }

  document.addEventListener('change', e => {
    if (e.target.classList.contains('bulk-select')) {
      requestAnimationFrame(updateBulkButtons);
    }
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

  if (bulkResetScraperBtn) {
    bulkResetScraperBtn.addEventListener('click', async () => {
      const checked = getChecked();
      if (!checked.length) return;
      const ok = await showConfirm(
        'Reset scrapers',
        `Reset OL and GR scraper status for ${checked.length} book(s)? They will be re-processed on the next import run.`
      );
      if (!ok) return;
      bulkResetScraperBtn.disabled = true;
      if (bulkStatus) bulkStatus.textContent = 'Resetting…';

      const rows = checked.map(cb => cb.closest('[data-book-block-id]')).filter(Boolean);
      const ids  = rows.map(r => r.dataset.bookBlockId).filter(Boolean);
      const fd = new FormData();
      ids.forEach(id => fd.append('book_ids[]', id));
      fd.append('targets', 'ol,gr');

      let statusMsg = '';
      try {
        const res  = await fetch('json_endpoints/reset_scraper_status.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
          const deleted = data.identifiers_deleted ?? 0;
          statusMsg = `Reset ${data.books_reset ?? ids.length} book(s), cleared ${deleted} identifier(s) — scrapers will retry on next run.`;
          // Clear GR data from affected rows so the UI reflects the reset immediately
          rows.forEach(row => {
            row.dataset.identifiers = '';
            const grIdCell = row.querySelector('.editable-cell[data-field="goodreads"]');
            if (grIdCell) {
              grIdCell.dataset.goodreads = '';
              const disp = grIdCell.querySelector('.cell-display');
              if (disp) disp.innerHTML = '<span class="text-muted" style="opacity:0.45;font-size:0.8em">+ gr id</span>';
            }
            const ratingCell = row.querySelector('.gr-rating-cell');
            if (ratingCell) ratingCell.textContent = '';
          });
        } else {
          statusMsg = 'Error: ' + (data.error ?? 'unknown');
        }
      } catch (err) {
        console.error(err);
        statusMsg = 'Request failed.';
      }
      updateBulkButtons();
      // Set status after updateBulkButtons so it isn't immediately overwritten by "N selected"
      if (bulkStatus && statusMsg) bulkStatus.textContent = statusMsg;
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

  // ── Inline autocomplete helper ────────────────────────────────────────────
  function setupInlineAutocomplete(input, endpoint, wrap) {
    const ul = document.createElement('ul');
    ul.className = 'list-group inline-suggest';
    wrap.appendChild(ul);

    let debounceTimer;
    let selectedIndex = -1;

    const clear = () => {
      ul.innerHTML = '';
      ul.style.display = 'none';
      selectedIndex = -1;
    };

    const render = items => {
      clear();
      if (!items.length) return;
      items.forEach(name => {
        const li = document.createElement('li');
        li.className = 'list-group-item list-group-item-action py-1 px-2 small';
        li.textContent = name;
        li.addEventListener('mousedown', e => {
          e.preventDefault();
          input.value = name;
          clear();
        });
        ul.appendChild(li);
      });
      ul.style.display = 'block';
    };

    input.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(async () => {
        const term = input.value.trim();
        if (term.length < 2) { clear(); return; }
        try {
          const res = await fetch(`json_endpoints/${endpoint}?term=${encodeURIComponent(term)}`);
          render(await res.json());
        } catch { /* ignore */ }
      }, 300);
    });

    input.addEventListener('keydown', e => {
      const items = ul.querySelectorAll('li');
      if (!items.length) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex = (selectedIndex + 1) % items.length;
        items.forEach((it, i) => it.classList.toggle('active', i === selectedIndex));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex = (selectedIndex - 1 + items.length) % items.length;
        items.forEach((it, i) => it.classList.toggle('active', i === selectedIndex));
      } else if (e.key === 'Enter' && selectedIndex >= 0) {
        e.preventDefault();
        input.value = items[selectedIndex].textContent;
        clear();
      }
    });

    input.addEventListener('blur', () => setTimeout(clear, 150));
    return ul;
  }

  // ── Inline editing (simple view) ─────────────────────────────────────────
  document.addEventListener('click', e => {
    if (e.target.closest('.author-info-btn')) return; // let author modal handler take it

    const display = e.target.closest('.cell-display');
    if (!display) return;

    const cell = display.closest('.editable-cell');
    if (!cell || cell.querySelector('.inline-edit-wrap')) return; // already editing

    e.stopPropagation();

    const bookId = cell.dataset.bookId;
    const field  = cell.dataset.field;

    let currentValue = '';
    let currentIndex = '';
    if (field === 'series') {
      currentValue = cell.dataset.seriesName || '';
      currentIndex = cell.dataset.seriesIndex || '';
    } else if (field === 'subseries') {
      currentValue = cell.dataset.subseriesName || '';
      currentIndex = cell.dataset.subseriesIndex || '';
    } else if (field === 'author') {
      currentValue = (cell.dataset.authors || '').split('|').filter(Boolean).join(', ');
    } else if (field === 'goodreads') {
      currentValue = cell.dataset.goodreads || '';
    } else {
      currentValue = display.textContent.trim();
    }

    // Build input(s) inside a flex wrapper
    const wrap = document.createElement('span');
    wrap.className = 'inline-edit-wrap';

    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentValue;
    input.className = 'inline-edit-input';
    wrap.appendChild(input);

    let indexInput = null;
    if (field === 'series' || field === 'subseries') {
      indexInput = document.createElement('input');
      indexInput.type = 'number';
      indexInput.step = '0.1';
      indexInput.min  = '0';
      indexInput.value = currentIndex;
      indexInput.className = 'inline-edit-input inline-edit-index';
      indexInput.placeholder = '#';
      wrap.appendChild(indexInput);
    }

    // Attach autocomplete for supported fields
    const autocompleteEndpoints = {
      author:    'author_autocomplete.php',
      series:    'series_autocomplete.php',
      subseries: 'subseries_autocomplete.php',
    };
    if (autocompleteEndpoints[field]) {
      setupInlineAutocomplete(input, autocompleteEndpoints[field], wrap);
    }

    cell.classList.add('editing');
    cell.appendChild(wrap);
    input.focus();
    input.select();

    function cancel() {
      wrap.remove();
      cell.classList.remove('editing');
    }

    let saving = false;
    async function save() {
      if (saving) return;
      const newValue = input.value.trim();
      const newIndex = indexInput ? indexInput.value.trim() : '';
      if (newValue === currentValue && newIndex === currentIndex) { cancel(); return; }
      saving = true;

      input.disabled = true;
      if (indexInput) indexInput.disabled = true;

      // Goodreads field: use save_identifier.php directly, mirroring book.php
      if (field === 'goodreads') {
        try {
          if (newValue !== '') {
            const saveRes  = await fetch('json_endpoints/save_identifier.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ book_id: parseInt(bookId, 10), type: 'goodreads', val: newValue }),
            });
            const saveData = await saveRes.json();
            if (!saveData.ok) {
              saving = false;
              alert('Failed to save: ' + (saveData.error || 'unknown error'));
              input.disabled = false;
              if (wrap.isConnected) input.focus();
              return;
            }
          } else {
            const delRes  = await fetch('json_endpoints/inline_edit.php', {
              method: 'POST',
              headers: { 'Accept': 'application/json' },
              body: new URLSearchParams({ book_id: bookId, field: 'goodreads', value: '' }),
            });
            const delData = await delRes.json();
            if (delData.status !== 'ok') {
              saving = false;
              alert(delData.error || 'Save failed');
              input.disabled = false;
              if (wrap.isConnected) input.focus();
              return;
            }
          }
          cell.dataset.goodreads = newValue;
          if (newValue) {
            display.textContent = newValue;
          } else {
            display.innerHTML = '<span class="text-muted" style="opacity:0.45;font-size:0.8em">+ gr id</span>';
          }
          const row = cell.closest('.simple-row[data-book-block-id]');
          if (row) {
            const pairs = (row.dataset.identifiers || '').split('|').filter(Boolean);
            const kept  = pairs.filter(p => !p.startsWith('goodreads:'));
            if (newValue) kept.push('goodreads:' + newValue);
            row.dataset.identifiers = kept.join('|');
          }
          const fd = new FormData();
          fd.append('book_id', bookId);
          fetch('json_endpoints/gr_unmark_done.php', { method: 'POST', body: fd }).catch(() => {});
          cancel();
        } catch (err) {
          saving = false;
          console.error('Inline save error (goodreads):', err);
          alert('Save failed');
          input.disabled = false;
          if (wrap.isConnected) input.focus();
        }
        return;
      }

      const body = new URLSearchParams({ book_id: bookId, field, value: newValue });
      if (indexInput) body.append('series_index', newIndex);

      try {
        const res  = await fetch('json_endpoints/inline_edit.php', {
          method: 'POST',
          headers: { 'Accept': 'application/json' },
          body,
        });
        const data = await res.json();

        if (data.status === 'ok') {
          if (field === 'title') {
            const link = display.querySelector('a');
            if (link) link.textContent = data.title;
            else display.textContent = data.title;

          } else if (field === 'author') {
            const names = data.authors ? data.authors.split('|') : [];
            display.textContent = names.slice(0, 3).join(', ') + (names.length > 3 ? '…' : '');
            cell.dataset.authors = data.authors || '';

          } else if (field === 'series') {
            cell.dataset.seriesName  = data.series || '';
            cell.dataset.seriesIndex = data.series_index != null ? String(data.series_index) : '';
            if (data.series) {
              display.textContent = data.series + (data.series_index != null ? ' (' + data.series_index + ')' : '');
            } else {
              display.textContent = '';
            }

          } else if (field === 'subseries') {
            cell.dataset.subseriesName  = data.subseries || '';
            cell.dataset.subseriesIndex = data.subseries_index != null ? String(data.subseries_index) : '';
            if (data.subseries) {
              display.textContent = '› ' + data.subseries + (data.subseries_index != null ? ' (' + data.subseries_index + ')' : '');
            } else {
              display.textContent = '';
            }
          }

          if (data.fs_warning) alert('Warning: ' + data.fs_warning);
          cancel();
        } else {
          saving = false;
          alert(data.error || 'Save failed');
          input.disabled = false;
          if (indexInput) indexInput.disabled = false;
          if (wrap.isConnected) input.focus();
        }
      } catch (err) {
        saving = false;
        console.error('Inline save error:', err);
        alert('Save failed');
        input.disabled = false;
        if (indexInput) indexInput.disabled = false;
        if (wrap.isConnected) input.focus();
      }
    }

    // Enter to save, Escape to cancel
    wrap.addEventListener('keydown', e => {
      if (e.key === 'Enter')  { e.preventDefault(); e.stopPropagation(); save(); }
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancel(); }
    });

    // Save when focus leaves the entire wrap
    wrap.addEventListener('focusout', e => {
      if (wrap.contains(e.relatedTarget)) return;
      save();
    });
  });

  // ── Similar books panels ─────────────────────────────────────────────────────
  const similarCache = {};

  function escHtmlSimilar(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function renderSimilarPanel(panel, books) {
    if (!books.length) {
      panel.innerHTML = '<p class="text-muted small mb-0">No similar books found.</p>';
      return;
    }
    const cards = books.slice(0, 10).map(b => {
      const cover = b.cover_url
        ? `<img src="${escHtmlSimilar(b.cover_url)}" alt="" class="similar-thumb" loading="lazy" onerror="this.style.display='none'">`
        : `<div class="similar-thumb-placeholder"><i class="fa-solid fa-book"></i></div>`;
      const seriesLine = b.series
        ? `<div class="similar-series">${escHtmlSimilar(b.series)}${b.series_position ? ' #' + escHtmlSimilar(b.series_position) : ''}</div>`
        : '';
      const href = b.in_library
        ? `book.php?id=${b.library_book_id}`
        : `https://www.goodreads.com/book/show/${escHtmlSimilar(b.gr_book_id)}`;
      const target = b.in_library ? '' : ' target="_blank" rel="noopener"';
      const inLibBadge = b.in_library ? '<span class="similar-in-lib">✓</span>' : '';
      return `<a href="${href}"${target} class="similar-thumb-card text-decoration-none">
        <div class="similar-thumb-img">${cover}${inLibBadge}</div>
        <div class="similar-thumb-title">${escHtmlSimilar(b.title)}</div>
        <div class="similar-thumb-author">${escHtmlSimilar(b.author)}</div>
        ${seriesLine}
      </a>`;
    }).join('');
    panel.innerHTML = `<div class="similar-thumb-row">${cards}</div>`;
  }

  async function openSimilarPanel(bookId, panel, link) {
    if (similarCache[bookId]) {
      renderSimilarPanel(panel, similarCache[bookId]);
      return;
    }
    panel.innerHTML = '<span class="text-muted small"><i class="fa-solid fa-spinner fa-spin me-1"></i>Loading…</span>';
    try {
      const res  = await fetch(`json_endpoints/fetch_gr_similar.php?book_id=${bookId}`);
      const data = await res.json();
      if (data.error) {
        panel.innerHTML = `<span class="text-muted small">${escHtmlSimilar(data.error)}</span>`;
        return;
      }
      similarCache[bookId] = data.books || [];
      renderSimilarPanel(panel, similarCache[bookId]);
    } catch (e) {
      panel.innerHTML = `<span class="text-muted small">Failed to load similar books.</span>`;
    }
  }

  function renderSimilarModalBooks(books) {
    return books.map(b => {
      const cover  = b.cover_url
          ? `<img src="${escHtmlSimilar(b.cover_url)}" alt="" class="similar-modal-cover" loading="lazy" onerror="this.style.display='none'">`
          : `<div class="similar-modal-cover-ph"><i class="fa-solid fa-book"></i></div>`;
      const series = b.series ? `<div class="text-muted" style="font-size:0.75rem">${escHtmlSimilar(b.series)}${b.series_position ? ' #' + escHtmlSimilar(b.series_position) : ''}</div>` : '';
      const rating = b.gr_rating ? `<span class="text-warning me-1" style="font-size:0.8rem">★${b.gr_rating.toFixed(2)}</span>` : '';
      const rcount = b.gr_rating_count ? `<span class="text-muted" style="font-size:0.75rem">${b.gr_rating_count.toLocaleString()}</span>` : '';
      const inLib  = b.in_library
          ? `<a href="book.php?id=${b.library_book_id}" class="badge bg-success text-decoration-none">In library</a>`
          : `<a href="https://www.goodreads.com/book/show/${escHtmlSimilar(b.gr_book_id)}" target="_blank" rel="noopener" class="badge bg-secondary text-decoration-none">Goodreads</a>`;
      const desc   = b.description
          ? `<div class="similar-modal-desc">${b.description}</div><button type="button" class="similar-modal-desc-toggle">View more</button>`
          : '';
      return `<div class="similar-modal-card">
        <div class="d-flex gap-3">
          <div class="flex-shrink-0">${cover}</div>
          <div class="flex-grow-1 overflow-hidden">
            <div class="fw-semibold" style="font-size:0.9rem">${escHtmlSimilar(b.title || '')}</div>
            <div class="text-muted small">${escHtmlSimilar(b.author || '')}</div>
            ${series}
            <div class="mt-1">${rating}${rcount}</div>
            <div class="mt-1">${inLib}</div>
            ${desc}
          </div>
        </div>
      </div>`;
    }).join('');
  }

  window.openSimilarModal = async function(bookId, title) {
    const modalEl   = document.getElementById('similarModal');
    const simBody   = document.getElementById('similarModalBody');
    const simStatus = document.getElementById('similarModalStatus');
    const simRefresh = document.getElementById('similarModalRefresh');
    if (!modalEl) return;

    // Wire up "View more / View less" toggle once per modal (idempotent via flag)
    if (!modalEl._descToggleBound) {
      modalEl._descToggleBound = true;
      document.getElementById('similarModalBody').addEventListener('click', e => {
        const btn = e.target.closest('.similar-modal-desc-toggle');
        if (!btn) return;
        const desc = btn.previousElementSibling;
        if (!desc) return;
        const expanded = desc.classList.toggle('expanded');
        btn.textContent = expanded ? 'View less' : 'View more';
      });
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById('similarModalLabel').innerHTML =
        `<i class="fa-solid fa-list-ul me-2"></i>${escHtmlSimilar(title)}`;
    simRefresh.style.display = 'none';
    simStatus.textContent    = '';

    async function doLoad(refresh) {
      simBody.innerHTML = '<div class="d-flex justify-content-center py-4"><div class="spinner-border text-secondary" role="status"></div></div>';
      simRefresh.style.display = 'none';
      try {
        const url  = `json_endpoints/fetch_gr_similar.php?book_id=${encodeURIComponent(bookId)}${refresh ? '&refresh=1' : ''}`;
        const res  = await fetch(url);
        const data = await res.json();
        if (data.error) {
          simBody.innerHTML = `<div class="alert alert-warning">${escHtmlSimilar(data.error)}</div>`;
          return;
        }
        const books = data.books || [];
        if (!books.length) {
          simBody.innerHTML = '<p class="text-muted">No similar books found for this title on Goodreads.</p>';
        } else {
          similarCache[bookId] = books;
          simBody.innerHTML = `<div class="similar-modal-grid">${renderSimilarModalBooks(books)}</div>`;
          simStatus.textContent = `${books.length} similar book${books.length !== 1 ? 's' : ''}`;
        }
        simRefresh.style.display = '';
        simRefresh.onclick = () => doLoad(true);
      } catch(err) {
        simBody.innerHTML = '<div class="alert alert-danger">Failed to load similar books.</div>';
      }
    }

    modal.show();
    if (similarCache[bookId]) {
      const books = similarCache[bookId];
      simBody.innerHTML = `<div class="similar-modal-grid">${renderSimilarModalBooks(books)}</div>`;
      simStatus.textContent = `${books.length} similar book${books.length !== 1 ? 's' : ''}`;
      simRefresh.style.display = '';
      simRefresh.onclick = () => doLoad(true);
    } else {
      doLoad(false);
    }
  };

  // Auto-load panels that have cached data (data-autoload set server-side).
  // Accepts an optional array of root elements to search within (for AJAX-loaded pages).
  function initSimilarPanels(roots = [document]) {
    roots.forEach(root => {
      root.querySelectorAll('.similar-panel[data-autoload]').forEach(panel => {
        const bookId = panel.id.replace('similar-panel-', '');
        openSimilarPanel(bookId, panel);
      });
    });
  }
  initSimilarPanels();

  // ── book_row_two description clamp toggle ────────────────────────────────────
  function initTwoDescToggles(roots = [document]) {
    roots.forEach(root => {
      root.querySelectorAll('.two-desc-clamped').forEach(desc => {
        const btn = desc.nextElementSibling;
        if (!btn || !btn.classList.contains('two-desc-toggle')) return;
        // Show button only if content is actually clamped (check after layout)
        requestAnimationFrame(() => {
          if (desc.scrollHeight > desc.clientHeight + 2) {
            btn.style.display = 'inline-block';
          }
        });
      });
    });
  }
  initTwoDescToggles();

  document.addEventListener('click', e => {
    const btn = e.target.closest('.two-desc-toggle');
    if (!btn) return;
    const desc = btn.previousElementSibling;
    if (!desc) return;
    const expanded = desc.classList.toggle('expanded');
    btn.textContent = expanded ? 'View less' : 'View more';
  });

  document.addEventListener('click', e => {
    const btn = e.target.closest('.two-rev-show-more');
    if (!btn) return;
    const more = btn.previousElementSibling;
    if (more) more.style.display = '';
    btn.remove();
  });

  // ── book_row_two section lazy loading ────────────────────────────────────────
  const twoRevCache = {};

  function renderTwoSimBody(body, books) {
    if (!books.length) {
      body.innerHTML = '<p class="text-muted small mb-0 py-2">No similar books found.</p>';
      return;
    }
    const cards = books.map(b => {
      const cover = b.cover_url
        ? `<img src="${escHtmlSimilar(b.cover_url)}" alt="" class="similar-thumb" loading="lazy" onerror="this.style.display='none'">`
        : `<div class="similar-thumb-placeholder"><i class="fa-solid fa-book"></i></div>`;
      const seriesLine = b.series
        ? `<div class="similar-series">${escHtmlSimilar(b.series)}${b.series_position ? ' #' + escHtmlSimilar(b.series_position) : ''}</div>`
        : '';
      const href = b.in_library
        ? `book-view.php?id=${b.library_book_id}`
        : `https://www.goodreads.com/book/show/${escHtmlSimilar(b.gr_book_id)}`;
      const target = b.in_library ? '' : ' target="_blank" rel="noopener"';
      const inLibBadge = b.in_library ? '<span class="similar-in-lib">✓</span>' : '';
      return `<a href="${href}"${target} class="similar-thumb-card text-decoration-none flex-shrink-0">
        <div class="similar-thumb-img">${cover}${inLibBadge}</div>
        <div class="similar-thumb-title">${escHtmlSimilar(b.title)}</div>
        <div class="similar-thumb-author">${escHtmlSimilar(b.author)}</div>
        ${seriesLine}
      </a>`;
    }).join('');
    body.innerHTML = `
      <div class="two-strip-wrap">
        <button class="two-strip-arrow two-strip-arrow-left" aria-label="Scroll left" style="display:none"><i class="fa-solid fa-chevron-left"></i></button>
        <div class="two-scroll-strip">${cards}</div>
        <button class="two-strip-arrow two-strip-arrow-right" aria-label="Scroll right" style="display:none"><i class="fa-solid fa-chevron-right"></i></button>
      </div>`;
    initStripArrows(body.querySelector('.two-strip-wrap'));
  }

  function initStripArrows(wrap) {
    const strip = wrap.querySelector('.two-scroll-strip');
    const left  = wrap.querySelector('.two-strip-arrow-left');
    const right = wrap.querySelector('.two-strip-arrow-right');
    const update = () => {
      left.style.display  = strip.scrollLeft > 4 ? '' : 'none';
      right.style.display = strip.scrollLeft < strip.scrollWidth - strip.clientWidth - 4 ? '' : 'none';
    };
    left.addEventListener('click',  () => strip.scrollBy({ left: -320, behavior: 'smooth' }));
    right.addEventListener('click', () => strip.scrollBy({ left:  320, behavior: 'smooth' }));
    strip.addEventListener('scroll', update, { passive: true });
    requestAnimationFrame(update);
  }

  function renderTwoRecBody(body, recs) {
    if (!recs.length) {
      body.innerHTML = '<p class="text-muted small mb-0 py-2">No recommendations found.</p>';
      return;
    }
    const cards = recs.map(r => `
      <div class="two-ai-card">
        <div class="two-ai-title">${escHtmlSimilar(r.title)}</div>
        <div class="two-ai-author">${escHtmlSimilar(r.author)}</div>
        ${r.reason ? `<div class="two-ai-reason">${escHtmlSimilar(r.reason)}</div>` : ''}
      </div>
    `).join('');
    body.innerHTML = `<div class="two-rec-wrap">${cards}</div>`;
  }

  function renderTwoRevBody(body, reviews) {
    if (!reviews.length) {
      body.innerHTML = '<p class="text-muted small mb-0 py-2">No reviews found.</p>';
      return;
    }
    const stars = r => r ? '★'.repeat(Math.round(r)) + '☆'.repeat(5 - Math.round(r)) : '';
    const renderCard = r => `
      <div class="two-rev-item">
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
          ${r.reviewer_url
            ? `<a href="${escHtmlSimilar(r.reviewer_url)}" target="_blank" rel="noopener" class="text-decoration-none fw-semibold small">${escHtmlSimilar(r.reviewer)}</a>`
            : `<span class="fw-semibold small">${escHtmlSimilar(r.reviewer || 'Anonymous')}</span>`}
          ${r.rating ? `<span class="text-warning small">${stars(r.rating)}</span>` : ''}
          ${r.review_date ? `<span class="text-muted" style="font-size:0.75rem">${escHtmlSimilar(r.review_date)}</span>` : ''}
          ${r.like_count > 0 ? `<span class="text-muted ms-auto" style="font-size:0.75rem">${r.like_count} likes</span>` : ''}
        </div>
        <div style="font-size:0.83rem;line-height:1.6">${r.text || ''}</div>
      </div>`;
    const visible = reviews.slice(0, 2);
    const hidden  = reviews.slice(2);
    const moreHtml = hidden.length
      ? `<div class="two-rev-more" style="display:none">${hidden.map(renderCard).join('')}</div>
         <button type="button" class="two-rev-show-more">Show all ${reviews.length} reviews</button>`
      : '';
    body.innerHTML = `<div class="two-rev-stack">${visible.map(renderCard).join('')}${moreHtml}</div>`;
  }

  // Init arrows on server-rendered series sibling strips
  function initServerStripArrows(roots = [document]) {
    roots.forEach(root => {
      root.querySelectorAll('.two-strip-wrap').forEach(wrap => {
        if (wrap.dataset.arrowsInit) return;
        wrap.dataset.arrowsInit = '1';
        initStripArrows(wrap);
      });
    });
  }
  initServerStripArrows();

  // Auto-render similar books and AI recs (always visible)
  function initTwoAutoSections(roots = [document]) {
    roots.forEach(root => {
      // Similar books — fetch from endpoint
      root.querySelectorAll('.two-sim-body[data-book-id]').forEach(async body => {
        if (body.dataset.loaded) return;
        body.dataset.loaded = '1';
        const bookId = body.dataset.bookId;
        body.innerHTML = '<span class="text-muted small"><i class="fa-solid fa-spinner fa-spin me-1"></i>Loading…</span>';
        try {
          if (similarCache[bookId]) { renderTwoSimBody(body, similarCache[bookId]); return; }
          const res  = await fetch(`json_endpoints/fetch_gr_similar.php?book_id=${bookId}`);
          const data = await res.json();
          if (data.error) { body.innerHTML = `<span class="text-muted small">${escHtmlSimilar(data.error)}</span>`; return; }
          similarCache[bookId] = data.books || [];
          renderTwoSimBody(body, similarCache[bookId]);
        } catch (_) { body.innerHTML = '<span class="text-muted small">Failed to load similar books.</span>'; }
      });
      // AI recs — parse from inline data attribute, no fetch needed
      root.querySelectorAll('.two-rec-body[data-rec-json]').forEach(body => {
        if (body.dataset.loaded) return;
        body.dataset.loaded = '1';
        try {
          const json = JSON.parse(body.dataset.recJson || '{}');
          renderTwoRecBody(body, json.recommendations || []);
        } catch (_) { body.innerHTML = '<span class="text-muted small">Failed to parse recommendations.</span>'; }
      });
    });
  }
  initTwoAutoSections();

  // Reviews section header click — toggle + lazy fetch
  document.addEventListener('click', async e => {
    const hdr = e.target.closest('.two-section-hdr');
    if (!hdr) return;
    const targetId = hdr.dataset.twoTarget;
    if (!targetId) return;
    const body = document.getElementById(targetId);
    if (!body) return;

    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : '';
    hdr.classList.toggle('open', !isOpen);
    if (isOpen || body.dataset.loaded) return;

    body.dataset.loaded = '1';
    const bookId = body.dataset.bookId;
    body.innerHTML = '<span class="text-muted small"><i class="fa-solid fa-spinner fa-spin me-1"></i>Loading…</span>';
    try {
      if (twoRevCache[bookId]) { renderTwoRevBody(body, twoRevCache[bookId]); return; }
      const res  = await fetch(`json_endpoints/book_reviews.php?book_id=${bookId}`);
      const data = await res.json();
      if (data.error) { body.innerHTML = `<span class="text-muted small">${escHtmlSimilar(data.error)}</span>`; return; }
      twoRevCache[bookId] = data.reviews || [];
      renderTwoRevBody(body, twoRevCache[bookId]);
    } catch (_) { body.innerHTML = '<span class="text-muted small">Failed to load reviews.</span>'; }
  });

  document.addEventListener('click', e => {
    const toggle = e.target.closest('.similar-toggle');
    if (!toggle) return;
    e.preventDefault();
    const bookId = toggle.dataset.bookId;
    const panel  = document.getElementById(`similar-panel-${bookId}`);
    if (!panel) return;
    if (panel.style.display === 'none' || panel.style.display === '') {
      panel.style.display = 'block';
      openSimilarPanel(bookId, panel);
    } else {
      panel.style.display = 'none';
    }
  });
});
