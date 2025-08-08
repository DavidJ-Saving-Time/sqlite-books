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

  const metadataBtn = document.getElementById('metadataBtn');
  const metadataResults = document.getElementById('metadataResults');
  const metadataModalEl = document.getElementById('metadataModal');
  let metadataModal = null;

  const ebookBtn = document.getElementById('ebookMetaBtn');
  const ebookFile = bodyData.ebookFile;
  const extractCoverBtn = document.getElementById('extractCoverBtn');
  const coverModalEl = document.getElementById('coverModal');
  const coverImgEl = document.getElementById('extractedCoverImg');
  const coverSizeEl = document.getElementById('extractedCoverSize');
  const useCoverBtn = document.getElementById('useExtractedCover');
  let coverModal = null;
  let extractedCoverData = '';

  recommendBtn.addEventListener('click', () => {
    const bookId = recommendBtn.dataset.bookId;
    const authors = recommendBtn.dataset.authors;
    const title = recommendBtn.dataset.title;
    recommendSection.textContent = 'Loading...';
    fetch('json_endpoints/recommend.php?book_id=' + encodeURIComponent(bookId) +
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
    fetch('json_endpoints/synopsis.php?book_id=' + encodeURIComponent(bookId) +
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

  if (metadataBtn && metadataModalEl && metadataResults) {
    metadataBtn.addEventListener('click', () => {
      metadataResults.textContent = 'Loading...';
      if (!metadataModal && window.bootstrap?.Modal) {
        try {
          metadataModal = new bootstrap.Modal(metadataModalEl);
        } catch (err) {
          console.error('Failed to init modal', err);
          metadataResults.textContent = 'Error loading modal';
          return;
        }
      }
      fetch('metadata/metadata.php?q=' + encodeURIComponent(bodyData.searchQuery))
        .then(r => r.json())
        .then(items => {
          if (!Array.isArray(items) || items.length === 0) {
            metadataResults.textContent = 'No results';
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
              if (it.cover) {
                html += `<img src="${escapeHTML(it.cover)}" style="height:100px" class="me-2 mb-1 meta-cover">`;
                html += '<div class="text-muted small mb-2 meta-cover-dim"></div>';
              }
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
          metadataResults.innerHTML = html;
          metadataResults.querySelectorAll('.meta-cover').forEach(imgEl => {
            const dimEl = imgEl.nextElementSibling;
            function setDim() {
              if (dimEl) {
                dimEl.textContent = `${imgEl.naturalWidth} × ${imgEl.naturalHeight}px`;
              }
            }
            if (imgEl.complete) {
              setDim();
            } else {
              imgEl.addEventListener('load', setDim, { once: true });
            }
            imgEl.addEventListener('error', () => {
              if (dimEl) dimEl.textContent = 'Image not found';
            }, { once: true });
          });
        })
        .catch(() => { metadataResults.textContent = 'Error fetching results'; });
      if (metadataModal) {
        metadataModal.show();
      }
    });
  }

  if (ebookBtn && ebookFile) {
    ebookBtn.addEventListener('click', () => {
      fetch('json_endpoints/ebook_meta.php?path=' + encodeURIComponent(ebookFile))
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

  if (extractCoverBtn && ebookFile && coverModalEl && coverImgEl && useCoverBtn) {
    extractCoverBtn.addEventListener('click', () => {
      coverSizeEl.textContent = '';
      fetch('json_endpoints/ebook_meta.php?path=' + encodeURIComponent(ebookFile))
        .then(r => r.json())
        .then(data => {
          if (data.cover) {
            extractedCoverData = data.cover;
            coverImgEl.src = 'data:image/jpeg;base64,' + data.cover;
            if (!coverModal && window.bootstrap?.Modal) {
              try {
                coverModal = new bootstrap.Modal(coverModalEl);
              } catch (err) {
                console.error('Failed to init modal', err);
                return;
              }
            }
            if (coverModal) coverModal.show();
          } else {
            alert('No cover found in file');
          }
        })
        .catch(() => { alert('Error extracting cover'); });
    });
    coverImgEl.addEventListener('load', () => {
      coverSizeEl.textContent = `${coverImgEl.naturalWidth} × ${coverImgEl.naturalHeight}px`;
    });
    useCoverBtn.addEventListener('click', () => {
      if (!extractedCoverData) return;
      const params = new URLSearchParams({ book_id: currentBookId, coverdata: extractedCoverData });
      fetch('json_endpoints/update_metadata.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
      }).then(r => r.json())
        .then(data => {
          if (data.status === 'ok') {
            if (coverModal) coverModal.hide();
            if (img) {
              const base = data.cover_url ? data.cover_url : img.src.split('?')[0];
              img.src = `${base}?t=${Date.now()}`;
              img.addEventListener('load', updateDimensions, { once: true });
            }
          } else {
            alert(data.error || 'Error saving cover');
          }
        })
        .catch(() => { alert('Error saving cover'); });
    });
  }

  document.addEventListener('click', e => {
    if (
      e.target.classList.contains('meta-use-cover') ||
      e.target.classList.contains('meta-use-desc') ||
      e.target.classList.contains('meta-use-both')
    ) {
      const imgurl = e.target.dataset.imgurl ? decodeURIComponent(e.target.dataset.imgurl) : '';
      const description = e.target.dataset.description ? decodeURIComponent(e.target.dataset.description) : '';
      const params = new URLSearchParams({ book_id: currentBookId });
      if (imgurl) params.append('imgurl', imgurl);
      if (description) params.append('description', description);
      fetch('json_endpoints/update_metadata.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
      }).then(r => r.json())
        .then(data => {
          if (data.status === 'ok') {
            if (metadataModal) metadataModal.hide();
            location.reload();
          } else {
            alert(data.error || 'Error updating metadata');
          }
        }).catch(() => {
          alert('Error updating metadata');
        });
    } else if (e.target.id === 'longitoodUseCover') {
      const url = e.target.dataset.url;
      fetch('json_endpoints/update_metadata.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: currentBookId, imgurl: url })
      }).then(r => r.json())
        .then(data => {
          if (data.status === 'ok') {
            if (img) {
              const base = img.src.split('?')[0];
              img.src = `${base}?t=${Date.now()}`;
              img.addEventListener('load', updateDimensions, { once: true });
            }
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
      fetch('json_endpoints/upload_book_file.php', {
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
        const res = await fetch(`json_endpoints/author_autocomplete.php?term=${encodeURIComponent(term)}`);
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
  const seriesIndexInput = document.getElementById('seriesIndex');
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
        const res = await fetch('json_endpoints/rename_series.php', {
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

  const subseriesSelect = document.getElementById('subseries');
  const newSubseriesInput = document.getElementById('newSubseriesInput');
  const addSubseriesBtn = document.getElementById('addSubseriesBtn');
  const editSubseriesBtn = document.getElementById('editSubseriesBtn');
  const subseriesIndexInput = document.getElementById('subseriesIndex');
  function toggleSubseriesInput() {
    if (!subseriesSelect) return;
    if (subseriesSelect.value === 'new') {
      if (newSubseriesInput) newSubseriesInput.style.display = '';
    } else {
      if (newSubseriesInput) {
        newSubseriesInput.style.display = 'none';
        if (subseriesSelect.value !== 'new') newSubseriesInput.value = '';
      }
    }
    if (editSubseriesBtn) {
      editSubseriesBtn.style.display = (subseriesSelect.value && subseriesSelect.value !== 'new') ? '' : 'none';
    }
  }
  if (subseriesSelect) {
    subseriesSelect.addEventListener('change', toggleSubseriesInput);
    toggleSubseriesInput();
  }
  if (addSubseriesBtn) {
    addSubseriesBtn.addEventListener('click', () => {
      if (subseriesSelect) {
        subseriesSelect.value = 'new';
        toggleSubseriesInput();
        if (newSubseriesInput) newSubseriesInput.focus();
      }
    });
  }
  if (editSubseriesBtn && subseriesSelect) {
    editSubseriesBtn.addEventListener('click', async () => {
      const id = subseriesSelect.value;
      if (!id || id === 'new') return;
      const option = subseriesSelect.options[subseriesSelect.selectedIndex];
      let name = prompt('Rename subseries:', option.textContent);
      if (name === null) return;
      name = name.trim();
      if (!name || name === option.textContent) return;
      try {
        const res = await fetch('json_endpoints/rename_subseries.php', {
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

  const swapSeriesSubseriesBtn = document.getElementById('swapSeriesSubseriesBtn');
  if (swapSeriesSubseriesBtn && seriesSelect && subseriesSelect) {
    swapSeriesSubseriesBtn.addEventListener('click', () => {
      const currentSeriesText = seriesSelect.value === 'new'
        ? (newSeriesInput ? newSeriesInput.value : '')
        : seriesSelect.options[seriesSelect.selectedIndex]?.text || '';
      const currentSubseriesText = subseriesSelect.value === 'new'
        ? (newSubseriesInput ? newSubseriesInput.value : '')
        : subseriesSelect.options[subseriesSelect.selectedIndex]?.text || '';

      // swap index numbers
      if (seriesIndexInput && subseriesIndexInput) {
        const tmpIdx = seriesIndexInput.value;
        seriesIndexInput.value = subseriesIndexInput.value;
        subseriesIndexInput.value = tmpIdx;
      }

      // helper to select by text or fallback to 'new'
      const selectByText = (select, text, newInput) => {
        const opt = Array.from(select.options).find(o => o.text === text);
        if (opt) {
          select.value = opt.value;
          if (newInput) newInput.value = '';
        } else {
          select.value = 'new';
          if (newInput) newInput.value = text;
        }
      };

      selectByText(seriesSelect, currentSubseriesText, newSeriesInput);
      selectByText(subseriesSelect, currentSeriesText, newSubseriesInput);

      toggleSeriesInput();
      toggleSubseriesInput();
    });
  }
});
