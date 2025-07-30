document.addEventListener('DOMContentLoaded', () => {
  const sortSelect = document.getElementById('sortSelect');
  if (sortSelect && sortSelect.form) {
    sortSelect.addEventListener('change', () => sortSelect.form.submit());
  }

  const shelfSelect = document.getElementById('shelfSelect');
  if (shelfSelect && shelfSelect.form) {
    shelfSelect.addEventListener('change', () => shelfSelect.form.submit());
  }
});
