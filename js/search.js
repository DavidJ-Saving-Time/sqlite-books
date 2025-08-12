document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.querySelector('input[name="search"]');
  const suggestionList = document.getElementById('searchSuggestions');

  if (searchInput && suggestionList) {
    let debounceTimer;
    let selectedIndex = -1;

    const clearSuggestions = () => {
      suggestionList.innerHTML = '';
      suggestionList.style.display = 'none';
      selectedIndex = -1;
    };

    const renderSuggestions = (items) => {
      clearSuggestions();
      if (!items.length) return;
      items.forEach(item => {
        const li = document.createElement('li');
        li.className = 'list-group-item list-group-item-action';
        li.dataset.value = item.value;

        const badge = document.createElement('span');
        badge.className = 'badge bg-secondary me-2';
        badge.textContent = item.type;
        li.appendChild(badge);
        li.appendChild(document.createTextNode(item.value));

        li.addEventListener('mousedown', (e) => {
          e.preventDefault();
          searchInput.value = li.dataset.value;
          clearSuggestions();
        });
        suggestionList.appendChild(li);
      });
      suggestionList.style.display = 'block';
    };

    const fetchSuggestions = async () => {
      const term = searchInput.value.trim();
      if (term.length < 2) { clearSuggestions(); return; }
      try {
        const [authorsRes, titlesRes, seriesRes] = await Promise.all([
          fetch(`json_endpoints/author_autocomplete.php?term=${encodeURIComponent(term)}`),
          fetch(`json_endpoints/title_autocomplete.php?term=${encodeURIComponent(term)}`),
          fetch(`json_endpoints/series_autocomplete.php?term=${encodeURIComponent(term)}`)
        ]);
        const [authors, titles, series] = await Promise.all([
          authorsRes.json(), titlesRes.json(), seriesRes.json()
        ]);
        const combined = [
          ...authors.map(name => ({ type: 'Author', value: name })),
          ...titles.map(name => ({ type: 'Title', value: name })),
          ...series.map(name => ({ type: 'Series', value: name }))
        ].slice(0, 10);
        renderSuggestions(combined);
      } catch (err) {
        console.error(err);
      }
    };

    searchInput.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(fetchSuggestions, 300);
    });

    searchInput.addEventListener('keydown', (e) => {
      const items = suggestionList.querySelectorAll('li');
      if (!items.length) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex = (selectedIndex + 1) % items.length;
        items.forEach((item, idx) => item.classList.toggle('active', idx === selectedIndex));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex = (selectedIndex - 1 + items.length) % items.length;
        items.forEach((item, idx) => item.classList.toggle('active', idx === selectedIndex));
      } else if (e.key === 'Enter') {
        if (selectedIndex >= 0) {
          e.preventDefault();
          searchInput.value = items[selectedIndex].dataset.value;
          clearSuggestions();
        }
      } else if (e.key === 'Escape') {
        clearSuggestions();
      }
    });

    document.addEventListener('click', (e) => {
      if (!suggestionList.contains(e.target) && e.target !== searchInput) {
        clearSuggestions();
      }
    });
  }

  const sourceSelect = document.querySelector('select[name="source"]');
  if (sourceSelect) {
    const form = sourceSelect.closest('form');
    const updateAction = () => {
      let action = 'list_books.php';
      switch (sourceSelect.value) {
        case 'openlibrary':
          action = 'openlibrary_results.php';
          break;
        case 'google':
          action = 'google_results.php';
          break;
        case 'annas':
          action = 'annas_results.php';
          break;
      }
      if (form) form.action = action;
    };
    sourceSelect.addEventListener('change', updateAction);
  }
});
