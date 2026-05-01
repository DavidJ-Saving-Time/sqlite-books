// authors.js — lazy-rendered author list from window.authorsData

const BATCH = 100;

const authorList      = document.getElementById('authorList');
const sentinel        = document.getElementById('authorSentinel');
const filterInput     = document.getElementById('authorFilter');
const zeroBooksToggle = document.getElementById('zeroBooksToggle');
const countEl         = document.getElementById('authorCount');
const alphabetBar     = document.getElementById('authorAlphabetBar');

let activeLetter = '';
let zeroOnly     = false;
let filtered     = [];   // current filtered slice of authorsData
let rendered     = 0;    // how many filtered rows are in the DOM

// ── HTML helpers ──────────────────────────────────────────────────────────────

function esc(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function buildOptions(values, placeholder) {
    return `<option value="">${placeholder}</option>`
        + values.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
}

function renderRow(a) {
    const row = document.createElement('div');
    row.className = 'author-row d-flex align-items-center gap-2 flex-wrap';
    row.dataset.name   = a.name.toLowerCase();
    row.dataset.letter = a.letter;
    row.dataset.books  = a.books;

    const badges = a.series.map(s =>
        `<a href="list_books.php?series_id=${s.id}" class="badge bg-secondary text-decoration-none author-series-badge">${esc(s.name)}</a>`
    ).join('');

    row.innerHTML = `
        <div class="flex-grow-1 d-flex align-items-center gap-2 flex-wrap">
            <a href="list_books.php?author_id=${a.id}" class="fw-semibold text-decoration-none author-link">
                <i class="fa-solid fa-user fa-xs me-1"></i>${esc(a.name)}
            </a>
            <span class="text-muted small">${a.books} book${a.books === 1 ? '' : 's'}</span>
            ${badges}
        </div>
        <div class="d-flex align-items-center gap-1 flex-shrink-0">
            <select class="form-select form-select-sm author-genre" data-author-id="${a.id}" style="width:9rem;">
                ${buildOptions(window.authorGenres, 'Genre\u2026')}
            </select>
            <select class="form-select form-select-sm author-status" data-author-id="${a.id}" style="width:9rem;">
                ${buildOptions(window.authorStatuses, 'Status\u2026')}
            </select>
            <button type="button" class="btn btn-sm btn-secondary rename-author"
                    data-author-id="${a.id}" data-name="${esc(a.name)}" title="Rename author">
                Rename
            </button>
            <button type="button" class="btn btn-sm btn-danger delete-author"
                    data-author-id="${a.id}" title="Delete author">
                <i class="fa-solid fa-trash"></i>
            </button>
        </div>`;
    return row;
}

// ── Render / filter ───────────────────────────────────────────────────────────

function applyFilters() {
    const q = filterInput.value.trim().toLowerCase();
    filtered = window.authorsData.filter(a =>
        (!q            || a.name.toLowerCase().includes(q))
     && (!activeLetter || a.letter === activeLetter)
     && (!zeroOnly     || a.books === 0)
    );
    rendered = 0;
    authorList.innerHTML = '';
    renderBatch();
    countEl.textContent = filtered.length + ' of ' + window.authorsData.length;
}

function renderBatch() {
    const end  = Math.min(rendered + BATCH, filtered.length);
    const frag = document.createDocumentFragment();
    for (let i = rendered; i < end; i++) {
        frag.appendChild(renderRow(filtered[i]));
    }
    rendered = end;
    authorList.appendChild(frag);
    sentinel.style.display = rendered < filtered.length ? 'block' : 'none';
}

// ── Intersection Observer for infinite scroll ─────────────────────────────────

new IntersectionObserver(entries => {
    if (entries[0].isIntersecting && rendered < filtered.length) {
        renderBatch();
    }
}, { rootMargin: '300px' }).observe(sentinel);

// ── Filter controls ───────────────────────────────────────────────────────────

filterInput.addEventListener('input', applyFilters);

zeroBooksToggle.addEventListener('click', () => {
    zeroOnly = !zeroOnly;
    zeroBooksToggle.classList.toggle('active',              zeroOnly);
    zeroBooksToggle.classList.toggle('btn-outline-warning', !zeroOnly);
    zeroBooksToggle.classList.toggle('btn-warning',         zeroOnly);
    applyFilters();
});

alphabetBar.addEventListener('click', e => {
    const btn = e.target.closest('.letter-btn');
    if (!btn) return;
    e.preventDefault();
    activeLetter = btn.dataset.letter;
    alphabetBar.querySelectorAll('.letter-btn').forEach(b => b.classList.toggle('active', b === btn));
    applyFilters();
});

// ── Genre / Status saves ──────────────────────────────────────────────────────

document.addEventListener('change', async e => {
    const el = e.target;
    let endpoint = null;
    if      (el.classList.contains('author-status')) endpoint = 'json_endpoints/update_author_status.php';
    else if (el.classList.contains('author-genre'))  endpoint = 'json_endpoints/update_author_genre.php';
    if (!endpoint) return;

    try {
        const res  = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ author_id: el.dataset.authorId, value: el.value }),
        });
        const data = await res.json();
        if (data.error) {
            alert('Save failed: ' + data.error);
        } else {
            el.classList.add('save-flash');
            el.addEventListener('animationend', () => el.classList.remove('save-flash'), { once: true });
        }
    } catch (err) {
        alert('Save failed: ' + err.message);
    }
});

// ── Rename author ─────────────────────────────────────────────────────────────

document.addEventListener('click', async e => {
    const btn = e.target.closest('.rename-author');
    if (!btn) return;

    const authorId  = parseInt(btn.dataset.authorId, 10);
    const current   = btn.dataset.name || '';
    const newName   = prompt('Rename author:', current);
    if (newName === null) return;
    const trimmed = newName.trim();
    if (!trimmed || trimmed === current) return;

    btn.disabled = true;
    try {
        const res  = await fetch('json_endpoints/rename_author.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ author_id: authorId, name: trimmed }),
        });
        const data = await res.json();
        if (data.status === 'ok') {
            // Update the data array so filter/re-render stays correct
            const entry = window.authorsData.find(a => a.id === authorId);
            if (entry) {
                entry.name   = data.name;
                entry.letter = data.name[0].toUpperCase().replace(/[^A-Z]/, '#');
            }
            // Update the visible row without a full re-render
            const row = btn.closest('.author-row');
            const link = row?.querySelector('.author-link');
            if (link) link.textContent = data.name;
            row.dataset.name = data.name.toLowerCase();
            btn.dataset.name = data.name;
        } else {
            alert('Rename failed: ' + (data.error || 'unknown error'));
        }
    } catch (err) {
        alert('Rename failed: ' + err.message);
    } finally {
        btn.disabled = false;
    }
});

// ── Delete author ─────────────────────────────────────────────────────────────

document.addEventListener('click', async e => {
    const btn = e.target.closest('.delete-author');
    if (!btn) return;
    if (!confirm('Delete this author and all associated books?')) return;

    const authorId = parseInt(btn.dataset.authorId, 10);
    btn.disabled = true;

    try {
        const res  = await fetch('json_endpoints/delete_author.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ author_id: authorId }),
        });
        const data = await res.json();
        if (data.status === 'ok') {
            // Remove from data array so it won't reappear on next filter
            window.authorsData = window.authorsData.filter(a => a.id !== authorId);
            btn.closest('.author-row').remove();
            countEl.textContent = document.querySelectorAll('#authorList .author-row').length
                + ' of ' + window.authorsData.length;
        } else {
            alert('Delete failed: ' + (data.error || 'unknown error'));
            btn.disabled = false;
        }
    } catch (err) {
        alert('Delete failed: ' + err.message);
        btn.disabled = false;
    }
});

// ── Init ──────────────────────────────────────────────────────────────────────
applyFilters();
