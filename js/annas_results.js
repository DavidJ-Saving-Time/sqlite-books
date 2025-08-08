document.addEventListener('click', async e => {
  const dl = e.target.closest('.annas-download');
  if (dl) {
    const md5 = dl.dataset.md5;
    if (!md5) return;
    try {
      const r = await fetch('json_endpoints/annas_download.php?md5=' + encodeURIComponent(md5));
      const data = await r.json();
      const url = data.url || (data.mirrors && data.mirrors[0]) || (Array.isArray(data) ? data[0] : null);
      if (url) {
        window.open(url, '_blank');
      } else {
        alert('Download link unavailable');
      }
    } catch (err) {
      alert('Download failed');
    }
    return;
  }
  const addBtn = e.target.closest('.annas-add');
  if (addBtn) {
    const { title, authors, thumbnail = '', description = '' } = addBtn.dataset;
    const resultEl = addBtn.parentElement.querySelector('.annas-add-result');
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
  }
});
