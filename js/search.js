document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.querySelector('input[name="search"]');
  const suggestionList = document.getElementById('authorSuggestions');
  if (searchInput) {
    const defaultWidth = searchInput.offsetWidth + 'px';
    searchInput.style.transition = 'width 0.3s';
    searchInput.addEventListener('focus', () => {
      searchInput.style.width = '20rem';
    });
    searchInput.addEventListener('blur', () => {
      searchInput.style.width = defaultWidth;
    });
  }
  if (searchInput && suggestionList) {
    searchInput.addEventListener('input', async () => {
      const term = searchInput.value.trim();
      suggestionList.innerHTML = '';
      if (term.length < 2) return;
      try {
        const res = await fetch(`author_autocomplete.php?term=${encodeURIComponent(term)}`);
        const data = await res.json();
        suggestionList.innerHTML = '';
        data.forEach(name => {
          const opt = document.createElement('option');
          opt.value = name;
          suggestionList.appendChild(opt);
        });
      } catch (err) {
        console.error(err);
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
