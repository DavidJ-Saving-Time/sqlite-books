document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.remove-challenge').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.bookId;
      await fetch('json_endpoints/update_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: id, value: 'Read' })
      });
      location.reload();
    });
  });
});
