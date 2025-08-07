document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.getElementById('authorSearch');
  if (searchInput) {
    const awesomplete = new Awesomplete(searchInput, { minChars: 2, autoFirst: true });
    searchInput.addEventListener('input', async () => {
      const term = searchInput.value.trim();
      if (term.length < 2) return awesomplete.list = [];
      try {
        const res = await fetch(`author_autocomplete.php?term=${encodeURIComponent(term)}`);
        awesomplete.list = await res.json();
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
