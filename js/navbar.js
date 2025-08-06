document.addEventListener('DOMContentLoaded', () => {
  const sortSelect = document.getElementById('sortSelect');
  if (sortSelect && sortSelect.form) {
    sortSelect.addEventListener('change', () => sortSelect.form.submit());
  }

  const shelfSelect = document.getElementById('shelfSelect');
  if (shelfSelect && shelfSelect.form) {
    shelfSelect.addEventListener('change', () => shelfSelect.form.submit());
  }

  const statusSelect = document.getElementById('statusSelect');
  if (statusSelect && statusSelect.form) {
    statusSelect.addEventListener('change', () => statusSelect.form.submit());
  }
});
