function escapeHTML(str) {
  return str.replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
}

function parseRecommendations(text) {
  const lines = text.split(/[\n\r]+/).map(l => l.trim()).filter(l => l);
  const recs = [];
  for (let line of lines) {
    line = line.replace(/^\d+\.\s*/, '').replace(/^[-*]\s*/, '');
    const byPos = line.toLowerCase().indexOf(' by ');
    if (byPos === -1) continue;
    let title = line.slice(0, byPos).trim();
    title = title.replace(/^['"*_]+|['"*_]+$/g, '');
    let rest = line.slice(byPos + 4).trim();
    let author, reason = '';
    const dashPos = rest.indexOf(' - ');
    if (dashPos !== -1) {
      author = rest.slice(0, dashPos).trim();
      reason = rest.slice(dashPos + 3).trim();
    } else {
      author = rest;
    }
    author = author.replace(/^['"*_]+|['"*_]+$/g, '');
    recs.push({ title, author, reason });
  }
  return recs;
}

function renderRecommendations(text) {
  const recs = parseRecommendations(text);
  if (!recs.length) {
    return '<p><strong>Recommendations:</strong> ' +
      escapeHTML(text).replace(/\n/g, '<br>') + '</p>';
  }
  let html = '<h2>Recommendations</h2><ol>';
  for (const r of recs) {
    const query = encodeURIComponent(r.title + ' ' + r.author);
    const link = '<a href="list_books.php?source=openlibrary&search=' + query + '">' +
      escapeHTML(r.title) + '</a>';
    html += '<li>' + link + ' by ' + escapeHTML(r.author);
    if (r.reason) html += ' - ' + escapeHTML(r.reason);
    html += '</li>';
  }
  html += '</ol>';
  return html;
}

document.addEventListener('DOMContentLoaded', () => {
  const recommendBtn = document.getElementById('recommendBtn');
  const recommendSection = document.getElementById('recommendSection');
  const synopsisBtn = document.getElementById('synopsisBtn');
  const descriptionInput = document.getElementById('description');
  const titleInput = document.getElementById('title');

  if (recommendSection.dataset.saved) {
    recommendSection.innerHTML = renderRecommendations(recommendSection.dataset.saved);
  }

  const bodyData = document.body.dataset;
  const currentBookId = parseInt(bodyData.bookId, 10);
  const annasSearchQuery = bodyData.searchQuery;
  const googleSearchQuery = bodyData.searchQuery;

  const annasBtn = document.getElementById('annasMetaBtn');
  const annasResults = document.getElementById('annasResults');
  const annasModalEl = document.getElementById('annasModal');
  const annasModal = new bootstrap.Modal(annasModalEl);

  const googleBtn = document.getElementById('googleMetaBtn');
  const googleResults = document.getElementById('googleResults');
  const googleModalEl = document.getElementById('googleModal');
  const googleModal = new bootstrap.Modal(googleModalEl);

  const ebookBtn = document.getElementById('ebookMetaBtn');
  const ebookFile = bodyData.ebookFile;

  recommendBtn.addEventListener('click', () => {
    const bookId = recommendBtn.dataset.bookId;
    const authors = recommendBtn.dataset.authors;
    const title = recommendBtn.dataset.title;
    recommendSection.textContent = 'Loading...';
    fetch('recommend.php?book_id=' + encodeURIComponent(bookId) +
          '&authors=' + encodeURIComponent(authors) + '&title=' + encodeURIComponent(title))
      .then(resp => resp.json())
      .then(data => {
        if (data.output) {
          recommendSection.innerHTML = renderRecommendations(data.output);
        } else {
          recommendSection.textContent = data.error || '';
        }
      })
      .catch(() => {
        recommendSection.textContent = 'Error fetching recommendations';
      });
  });

  synopsisBtn.addEventListener('click', () => {
    const bookId = synopsisBtn.dataset.bookId;
    const authors = synopsisBtn.dataset.authors;
    const title = synopsisBtn.dataset.title;
    if (descriptionInput) {
      descriptionInput.value = 'Loading...';
    }
    fetch('synopsis.php?book_id=' + encodeURIComponent(bookId) +
          '&authors=' + encodeURIComponent(authors) + '&title=' + encodeURIComponent(title))
      .then(resp => resp.json())
      .then(data => {
        if (descriptionInput) {
          if (data.output) {
            descriptionInput.value = data.output;
          } else {
            descriptionInput.value = data.error || 'Error';
          }
        }
      })
      .catch(() => {
        if (descriptionInput) {
          descriptionInput.value = 'Error fetching synopsis';
        }
      });
  });

  annasBtn.addEventListener('click', () => {
    annasResults.textContent = 'Loading...';
    fetch('annas_search.php?q=' + encodeURIComponent(annasSearchQuery))
      .then(r => r.json())
      .then(data => {
        if (!data.books || data.books.length === 0) {
          annasResults.textContent = 'No results';
          return;
        }
        let html = '';
        data.books.forEach(b => {
          html += '<div class="mb-2">';
          if (b.imgUrl) html += '<img src="' + escapeHTML(b.imgUrl) + '" style="height:100px" class="me-2">';
          html += '<strong>' + escapeHTML(b.title) + '</strong>';
          if (b.author) html += ' by ' + escapeHTML(b.author);
          if (b.year) html += ' (' + escapeHTML(b.year) + ')';
          html += '<div><button type="button" class="btn btn-sm btn-primary mt-1 annas-use" '
                    + 'data-title="' + b.title.replace(/"/g,'&quot;') + '" '
                    + 'data-authors="' + (b.author || '').replace(/"/g,'&quot;') + '" '
                    + 'data-year="' + (b.year || '').replace(/"/g,'&quot;') + '" '
                    + 'data-imgurl="' + (b.imgUrl || '').replace(/"/g,'&quot;') + '" '
                    + 'data-md5="' + (b.md5 || '').replace(/"/g,'&quot;') + '">Use This</button></div>';
          html += '</div>';
        });
        annasResults.innerHTML = html;
      })
      .catch(() => { annasResults.textContent = 'Error fetching results'; });
    annasModal.show();
  });

  googleBtn.addEventListener('click', () => {
    googleResults.textContent = 'Loading...';
    fetch('google_search.php?q=' + encodeURIComponent(googleSearchQuery))
      .then(r => r.json())
      .then(data => {
        if (!data.books || data.books.length === 0) {
          googleResults.textContent = 'No results';
          return;
        }
        let html = '';
        data.books.forEach(b => {
          html += '<div class="mb-2">';
          if (b.imgUrl) html += '<img src="' + escapeHTML(b.imgUrl) + '" style="height:100px" class="me-2">';
          html += '<strong>' + escapeHTML(b.title) + '</strong>';
          if (b.author) html += ' by ' + escapeHTML(b.author);
          if (b.year) html += ' (' + escapeHTML(b.year) + ')';
          if (b.description) html += '<br><em>' + escapeHTML(b.description) + '</em>';
          html += '<div><button type="button" class="btn btn-sm btn-primary mt-1 google-use" '
                    + 'data-title="' + b.title.replace(/"/g,'&quot;') + '" '
                    + 'data-authors="' + (b.author || '').replace(/"/g,'&quot;') + '" '
                    + 'data-year="' + (b.year || '').replace(/"/g,'&quot;') + '" '
                    + 'data-imgurl="' + (b.imgUrl || '').replace(/"/g,'&quot;') + '" '
                    + 'data-description="' + (b.description || '').replace(/"/g,'&quot;') + '">Use This</button></div>';
          html += '</div>';
        });
        googleResults.innerHTML = html;
      })
      .catch(() => { googleResults.textContent = 'Error fetching results'; });
    googleModal.show();
  });

  if (ebookBtn && ebookFile) {
    ebookBtn.addEventListener('click', () => {
      fetch('ebook_meta.php?path=' + encodeURIComponent(ebookFile))
        .then(r => r.json())
        .then(data => {
          if (data.title && titleInput) titleInput.value = data.title;
          if (data.authors && authorInput) {
            if (Array.isArray(data.authors)) {
              authorInput.value = data.authors.join(', ');
            } else {
              authorInput.value = String(data.authors).replace(/ and /g, ', ');
            }
            updateAuthorSort();
          }
          if (data.comments && descriptionInput) {
            descriptionInput.value = data.comments;
          }
        })
        .catch(() => { alert('Error reading metadata'); });
    });
  }

  document.addEventListener('click', e => {
    if (e.target.classList.contains('annas-use')) {
      const { title, authors, year, imgurl, md5 = '' } = e.target.dataset;
      fetch('update_metadata.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: currentBookId, title, authors, year, imgurl, md5 })
      }).then(r => r.json())
        .then(data => {
          if (data.status === 'ok') {
            annasModal.hide();
            location.reload();
          } else {
            alert(data.error || 'Error updating metadata');
          }
        }).catch(() => {
          alert('Error updating metadata');
        });
    } else if (e.target.classList.contains('google-use')) {
      const { title, authors, year, imgurl, description = '' } = e.target.dataset;
      fetch('update_metadata.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: currentBookId, title, authors, year, imgurl, description })
      }).then(r => r.json())
        .then(data => {
          if (data.status === 'ok') {
            googleModal.hide();
            location.reload();
          } else {
            alert(data.error || 'Error updating metadata');
          }
        }).catch(() => {
          alert('Error updating metadata');
        });
    } else if (e.target.id === 'longitoodUseCover') {
      const url = e.target.dataset.url;
      fetch('update_metadata.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: currentBookId, imgurl: url })
      }).then(r => r.json())
        .then(data => {
          if (data.status === 'ok') {
            location.reload();
          } else {
            alert(data.error || 'Error saving cover');
          }
        }).catch(() => {
          alert('Error saving cover');
        });
    }
  });

  const uploadBtn = document.getElementById('uploadFileButton');
  const uploadInput = document.getElementById('bookFileInput');
  const uploadMsg = document.getElementById('uploadMessage');

  if (uploadBtn) {
    uploadBtn.addEventListener('click', () => uploadInput.click());
    uploadInput.addEventListener('change', () => {
      if (!uploadInput.files.length) return;
      const formData = new FormData();
      formData.append('id', currentBookId);
      formData.append('file', uploadInput.files[0]);
      uploadMsg.textContent = 'Uploading...';
      fetch('upload_book_file.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: formData
      }).then(r => r.json())
        .then(data => {
          if (data.status === 'ok') {
            uploadMsg.textContent = data.message || 'File uploaded';
          } else {
            uploadMsg.textContent = data.error || 'Upload failed';
          }
        })
        .catch(() => {
          uploadMsg.textContent = 'Upload failed';
        });
    });
  }

  const img = document.getElementById('coverImagePreview');
  const dimLabel = document.getElementById('coverDimensions');
  const coverInput = document.getElementById('cover');
  const isbnCoverWrap = document.getElementById('isbnCover');
  const isbnInput = document.getElementById('isbn');
  const initialIsbn = bodyData.isbn || (isbnInput ? isbnInput.value.trim() : '');

  async function fetchIsbnCover(val) {
    if (!isbnCoverWrap || !val) return;
    isbnCoverWrap.textContent = 'Looking up cover...';
    try {
      const res = await fetch(`https://bookcover.longitood.com/bookcover/${encodeURIComponent(val)}`);
      const data = await res.json();
      if (data && data.url) {
        const u = data.url;
        isbnCoverWrap.innerHTML =
          `<img src="${escapeHTML(u)}" class="img-thumbnail mb-2" style="max-height:150px">` +
          `<div><button type="button" class="btn btn-sm btn-primary" id="longitoodUseCover" data-url="${u.replace(/"/g,'&quot;')}">Use This</button></div>`;
      } else {
        isbnCoverWrap.textContent = 'No cover found';
      }
    } catch (err) {
      isbnCoverWrap.textContent = 'Error fetching cover';
    }
  }

  if (initialIsbn) {
    fetchIsbnCover(initialIsbn);
  }
  if (isbnInput) {
    isbnInput.addEventListener('change', () => {
      const val = isbnInput.value.trim();
      if (val) fetchIsbnCover(val);
    });
  }
  function updateDimensions() {
    if (!img || !dimLabel) return;
    if (img.naturalWidth && img.naturalHeight) {
      dimLabel.textContent = `${img.naturalWidth} × ${img.naturalHeight}px`;
    } else {
      dimLabel.textContent = 'No image data';
    }
  }
  if (img) {
    if (img.complete) {
      updateDimensions();
    } else {
      img.addEventListener('load', updateDimensions);
      if (dimLabel) {
        img.addEventListener('error', () => { dimLabel.textContent = 'Image not found'; });
      }
    }
  }

  if (coverInput && img) {
    coverInput.addEventListener('change', () => {
      if (!coverInput.files.length) return;
      const file = coverInput.files[0];
      const url = URL.createObjectURL(file);
      img.src = url;
      img.addEventListener('load', () => {
        updateDimensions();
        URL.revokeObjectURL(url);
      }, { once: true });
    });
  }

  const authorInput = document.getElementById('authors');
  const authorSortInput = document.getElementById('authorSort');
  const applySortBtn = document.getElementById('applyAuthorSortBtn');
  const suggestionList = document.getElementById('authorSuggestionsEdit');
  function calcAuthorSort(str) {
    const particles = ['da','de','del','della','di','du','la','le','van','von','der','den','ter','ten','el'];
    const suffixes  = ['jr','jr.','sr','sr.','ii','iii','iv'];

    function invert(name) {
      name = name.trim();
      if (!name) return '';
      if (name.includes(',')) return name;

      const parts = name.split(/\s+/);
      if (parts.length <= 1) return name;

      let suffix = '';
      const last = parts[parts.length - 1].toLowerCase();
      if (suffixes.includes(last)) {
        suffix = ' ' + parts.pop();
      }

      let lastName = parts.pop();
      while (parts.length > 0 && particles.includes(parts[parts.length - 1].toLowerCase())) {
        lastName = parts.pop() + ' ' + lastName;
      }

      const firstNames = parts.join(' ');
      return `${lastName}${suffix}, ${firstNames}`.trim();
    }

    const authors = str.split(/\s*(?:&| and )\s*/i).filter(a => a.trim());
    const sorted = authors.map(a => invert(a));
    return sorted.join(' & ');
  }
  function updateAuthorSort() {
    if (authorInput && authorSortInput) {
      authorSortInput.value = calcAuthorSort(authorInput.value);
    }
  }
  if (authorInput && suggestionList) {
    authorInput.addEventListener('input', async () => {
      const term = authorInput.value.trim();
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
      } catch (err) { console.error(err); }
    });
    if (authorSortInput) {
      authorInput.addEventListener('input', updateAuthorSort);
      if (!authorSortInput.value) updateAuthorSort();
    }
  }
  if (applySortBtn) {
    applySortBtn.addEventListener('click', updateAuthorSort);
  }

  const seriesSelect = document.getElementById('series');
  const newSeriesInput = document.getElementById('newSeriesInput');
  const addSeriesBtn = document.getElementById('addSeriesBtn');
  const editSeriesBtn = document.getElementById('editSeriesBtn');
  function toggleSeriesInput() {
    if (!seriesSelect) return;
    if (seriesSelect.value === 'new') {
      newSeriesInput.style.display = '';
    } else {
      newSeriesInput.style.display = 'none';
      if (seriesSelect.value !== 'new') newSeriesInput.value = '';
    }
    if (editSeriesBtn) {
      editSeriesBtn.style.display = (seriesSelect.value && seriesSelect.value !== 'new') ? '' : 'none';
    }
  }
  if (seriesSelect && newSeriesInput) {
    seriesSelect.addEventListener('change', toggleSeriesInput);
    toggleSeriesInput();
  }
  if (addSeriesBtn) {
    addSeriesBtn.addEventListener('click', () => {
      if (seriesSelect) {
        seriesSelect.value = 'new';
        toggleSeriesInput();
        newSeriesInput.focus();
      }
    });
  }
  if (editSeriesBtn && seriesSelect) {
    editSeriesBtn.addEventListener('click', async () => {
      const id = seriesSelect.value;
      if (!id || id === 'new') return;
      const option = seriesSelect.options[seriesSelect.selectedIndex];
      let name = prompt('Rename series:', option.textContent);
      if (name === null) return;
      name = name.trim();
      if (!name || name === option.textContent) return;
      try {
        const res = await fetch('rename_series.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ id, new: name })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          option.textContent = name;
        }
      } catch (err) { console.error(err); }
    });
  }
});
