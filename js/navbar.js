document.addEventListener('DOMContentLoaded', () => {
  const select = document.getElementById('sortSelect');
  if (select && select.form) {
    select.addEventListener('change', () => select.form.submit());
  }
});
