document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.querySelector('input[name="search"]');
  const suggestionList = document.getElementById('authorSuggestions');

  if (searchInput && suggestionList) {
    let debounceTimer;
    let selectedIndex = -1;

    const clearSuggestions = () => {
      suggestionList.innerHTML = '';
      suggestionList.style.display = 'none';
      selectedIndex = -1;
    };

    const renderSuggestions = (data) => {
      clearSuggestions();
      if (!data.length) return;
      data.forEach(name => {
        const li = document.createElement('li');
        li.textContent = name;
        li.className = 'list-group-item list-group-item-action';
        li.addEventListener('mousedown', (e) => {
          e.preventDefault();
          searchInput.value = name;
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
        const res = await fetch(`json_endpoints/author_autocomplete.php?term=${encodeURIComponent(term)}`);
        const data = await res.json();
        renderSuggestions(data);
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
          searchInput.value = items[selectedIndex].textContent;
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
