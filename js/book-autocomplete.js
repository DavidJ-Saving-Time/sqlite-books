/**
 * BookAutocomplete — reusable library book search widget.
 *
 * Usage:
 *   const ac = new BookAutocomplete({
 *     input:    '#my-text-input',   // CSS selector or element
 *     hidden:   '#my-hidden-input', // receives the selected book id
 *     params:   { with_files: 1 },  // extra query params for the endpoint
 *     onSelect: (book) => { ... },  // called with { id, title, author, [file] }
 *     onClear:  () => { ... },      // called when selection is cleared
 *   });
 *
 *   ac.setValue(book)  — programmatically select a book object
 *   ac.clear()         — clear input and hidden field
 */
class BookAutocomplete {
    constructor({ input, hidden, endpoint = '/json_endpoints/library_book_search.php', params = {}, onSelect = null, onClear = null }) {
        this.input    = typeof input  === 'string' ? document.querySelector(input)  : input;
        this.hidden   = typeof hidden === 'string' ? document.querySelector(hidden) : hidden;
        this.endpoint = endpoint;
        this.params   = params;
        this.onSelect = onSelect;
        this.onClear  = onClear;
        this.active   = -1;
        this.matches  = [];
        this._timer   = null;

        // Dropdown list
        this.list = document.createElement('ul');
        this.list.style.cssText = [
            'display:none',
            'position:absolute',
            'left:0',
            'right:0',
            'top:100%',
            'z-index:500',
            'margin:2px 0 0',
            'padding:0',
            'list-style:none',
            'background:var(--surface-2,#fff)',
            'border:1px solid var(--border-mid,#ccc)',
            'border-radius:4px',
            'max-height:280px',
            'overflow-y:auto',
            'box-shadow:0 8px 24px rgba(0,0,0,0.45)',
        ].join(';');

        const wrapper = this.input.parentElement;
        if (getComputedStyle(wrapper).position === 'static') {
            wrapper.style.position = 'relative';
        }
        wrapper.appendChild(this.list);

        this._bind();
    }

    _bind() {
        this.input.addEventListener('input', () => {
            this.hidden.value = '';
            clearTimeout(this._timer);
            const term = this.input.value.trim();
            if (!term) { this._close(); return; }
            this._timer = setTimeout(() => this._fetch(term), 220);
        });

        this.input.addEventListener('keydown', e => {
            if (this.list.style.display === 'none') return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this._highlight(Math.min(this.active + 1, this.matches.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this._highlight(Math.max(this.active - 1, 0));
            } else if (e.key === 'Enter' && this.active >= 0) {
                e.preventDefault();
                this._select(this.matches[this.active]);
            } else if (e.key === 'Escape') {
                this._close();
            }
        });

        this.input.addEventListener('blur', () => {
            setTimeout(() => this._close(), 160);
        });
    }

    async _fetch(term) {
        const url = new URL(this.endpoint, location.origin);
        url.searchParams.set('q', term);
        Object.entries(this.params).forEach(([k, v]) => url.searchParams.set(k, v));
        try {
            const res    = await fetch(url);
            this.matches = await res.json();
            this._render();
        } catch {
            this._close();
        }
    }

    _render() {
        this.list.innerHTML = '';
        this.active = -1;
        if (!this.matches.length) { this._close(); return; }
        this.matches.forEach((book, i) => {
            const li = document.createElement('li');
            li.style.cssText = 'padding:0.55rem 0.85rem;cursor:pointer;border-bottom:1px solid var(--border,#eee);';
            li.innerHTML =
                `<span style="color:var(--text,inherit);">${this._esc(book.title)}</span>` +
                (book.author
                    ? `<span style="display:block;font-size:0.8em;color:var(--text-muted,#888);font-style:italic;">${this._esc(book.author)}</span>`
                    : '');
            li.addEventListener('mousedown', e => { e.preventDefault(); this._select(book); });
            li.addEventListener('mouseenter', () => this._highlight(i));
            this.list.appendChild(li);
        });
        this.list.style.display = '';
    }

    _highlight(idx) {
        Array.from(this.list.children).forEach((li, i) => {
            li.style.background = i === idx ? 'var(--accent-dim,rgba(0,0,0,0.08))' : '';
        });
        this.active = idx;
    }

    _select(book) {
        this.input.value  = book.title + (book.author ? ' — ' + book.author : '');
        this.hidden.value = book.id;
        this._close();
        if (this.onSelect) this.onSelect(book);
    }

    _close() {
        this.list.style.display = 'none';
        this.active = -1;
    }

    _esc(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /** Programmatically set the selected book (e.g. for pre-fill on page load). */
    setValue(book) {
        if (!book) return;
        this._select(book);
    }

    /** Programmatically fetch a book by id and select it. */
    async selectById(id, extraParams = {}) {
        const url = new URL(this.endpoint, location.origin);
        url.searchParams.set('by_id', id);
        Object.entries({ ...this.params, ...extraParams }).forEach(([k, v]) => url.searchParams.set(k, v));
        try {
            const res  = await fetch(url);
            const book = await res.json();
            if (book) this._select(book);
        } catch {}
    }

    clear() {
        this.input.value  = '';
        this.hidden.value = '';
        this._close();
        if (this.onClear) this.onClear();
    }
}
