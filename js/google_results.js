document.addEventListener('click', async e => {
  const addBtn = e.target.closest('.google-add');
  if (!addBtn) return;
  const { title, authors, thumbnail = '', description = '' } = addBtn.dataset;
  const resultEl = addBtn.parentElement.querySelector('.google-add-result');
  if (resultEl) resultEl.textContent = 'Adding...';
  try {
    const r = await fetch('json_endpoints/add_metadata_book.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ title, authors, thumbnail, description })
    });
    const data = await r.json();
    if (data.status === 'ok') {
      window.location.href = 'list_books.php?search=' + encodeURIComponent(title) + '&source=local';
    } else if (resultEl) {
      resultEl.textContent = data.error || 'Error adding';
    }
  } catch (err) {
    if (resultEl) resultEl.textContent = 'Error adding';
  }
});
