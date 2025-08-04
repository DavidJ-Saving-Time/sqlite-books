<div class="modal fade" id="metadataModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Metadata Results</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="metadataResults">Loading...</div>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('metadataModal');
  if (!modalEl) return;
  modalEl.addEventListener('show.bs.modal', () => {
    const query = document.body.dataset.searchQuery || '';
    const resultsDiv = document.getElementById('metadataResults');
    resultsDiv.textContent = 'Loading...';
    fetch('metadata/metadata.php?q=' + encodeURIComponent(query))
      .then(r => r.json())
      .then(items => {
        if (!Array.isArray(items) || items.length === 0) {
          resultsDiv.textContent = 'No results';
          return;
        }
        const groups = {};
        for (const item of items) {
          const src = item.source_id || item.source || 'Unknown';
          (groups[src] = groups[src] || []).push(item);
        }
        let html = '';
        for (const [src, arr] of Object.entries(groups)) {
          html += `<h5 class="mt-3">${escapeHTML(src.replace(/_/g, ' '))}</h5>`;
          for (const it of arr) {
            html += '<div class="mb-3">';
            if (it.cover) html += `<img src="${escapeHTML(it.cover)}" style="height:100px" class="me-2 mb-2">`;
            html += `<strong>${escapeHTML(it.title || '')}</strong>`;
            if (it.authors) html += ' by ' + escapeHTML(it.authors);
            if (it.year) html += ` (${escapeHTML(String(it.year))})`;
            if (it.description) html += `<div><em>${escapeHTML(it.description)}</em></div>`;
            html += '<div class="mt-1">';
            if (it.cover) html += `<button type="button" class="btn btn-sm btn-primary me-1 meta-use-cover" data-imgurl="${encodeURIComponent(it.cover)}">Use Cover</button>`;
            if (it.description) html += `<button type="button" class="btn btn-sm btn-secondary me-1 meta-use-desc" data-description="${encodeURIComponent(it.description)}">Use Description</button>`;
            if (it.cover && it.description) html += `<button type="button" class="btn btn-sm btn-success me-1 meta-use-both" data-imgurl="${encodeURIComponent(it.cover)}" data-description="${encodeURIComponent(it.description)}">Use Both</button>`;
            html += '</div></div>';
          }
        }
        resultsDiv.innerHTML = html;
      })
      .catch(() => { resultsDiv.textContent = 'Error fetching results'; });
  });
});
</script>
