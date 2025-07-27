(function(){
  function initAutocomplete(){
    const searchInput = document.querySelector('input[name="search"]');
    const suggestionList = document.getElementById('authorSuggestions');
    if (!searchInput || !suggestionList) return;
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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAutocomplete);
  } else {
    initAutocomplete();
  }
})();
