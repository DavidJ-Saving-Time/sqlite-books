// Handle status changes and author deletions on authors page

document.addEventListener('change', async e => {
  if (e.target.classList.contains('author-status')) {
    const authorId = e.target.dataset.authorId;
    const value = e.target.value;
    await fetch('json_endpoints/update_author_status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ author_id: authorId, value })
    });
  } else if (e.target.classList.contains('author-genre')) {
    const authorId = e.target.dataset.authorId;
    const value = e.target.value;
    await fetch('json_endpoints/update_author_genre.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ author_id: authorId, value })
    });
  }
});

document.addEventListener('click', async e => {
  const btn = e.target.closest('.delete-author');
  if (!btn) return;
  if (!confirm('Delete this author and all associated books?')) return;
  const authorId = btn.dataset.authorId;
  try {
    const res = await fetch('json_endpoints/delete_author.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ author_id: authorId })
    });
    const data = await res.json();
    if (data.status === 'ok') {
      btn.closest('li').remove();
    }
  } catch (err) {
    console.error(err);
  }
});
