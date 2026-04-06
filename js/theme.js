(function () {
    'use strict';

    const API_URL      = 'https://bootswatch.com/api/5.json';
    const DEFAULT_CSS  = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
    const DEFAULT_NAME = 'Bootstrap (default)';

    // Populate the Bootswatch <select> and wire up live preview
    async function init() {
        const select = document.getElementById('themeSelect');
        if (!select) return;

        const current = document.getElementById('bootswatchUrl')?.value || DEFAULT_CSS;

        let themes = [{ name: DEFAULT_NAME, css: DEFAULT_CSS }];
        try {
            const r    = await fetch(API_URL);
            const data = await r.json();
            themes = themes.concat(data.themes.map(t => ({ name: t.name, css: t.cssCdn })));
        } catch (_) { /* use default only */ }

        select.innerHTML = '';
        themes.forEach(t => {
            const opt      = document.createElement('option');
            opt.value      = t.css;
            opt.textContent = t.name;
            if (t.css === current) opt.selected = true;
            select.appendChild(opt);
        });

        select.addEventListener('change', () => {
            // Update hidden field so the form submits the new URL
            const hidden = document.getElementById('bootswatchUrl');
            if (hidden) hidden.value = select.value;

            // Live preview: append/update a <link> that overrides the @import in theme.css.php
            let preview = document.getElementById('themePreviewLink');
            if (!preview) {
                preview    = document.createElement('link');
                preview.rel = 'stylesheet';
                preview.id  = 'themePreviewLink';
                document.head.appendChild(preview);
            }
            preview.href = select.value;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
