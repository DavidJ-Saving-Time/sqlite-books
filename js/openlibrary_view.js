document.addEventListener('DOMContentLoaded', () => {
  const addBtn = document.getElementById('addBtn');
  const resultEl = document.getElementById('addResult');
  if (!addBtn) return;
  addBtn.addEventListener('click', async () => {
    const { title, authors, thumbnail = '', description = '' } = addBtn.dataset;
    resultEl.textContent = 'Adding...';
    try {
      const r = await fetch('add_metadata_book.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ title, authors, thumbnail, description })
      });
      const data = await r.json();
      if (data.status === 'ok') {
        window.location.href = 'list_books.php?search=' + encodeURIComponent(title) + '&source=local';
      } else {
        resultEl.textContent = data.error || 'Error adding book';
      }
    } catch (err) {
      resultEl.textContent = 'Error adding book';
    }
  });
});
