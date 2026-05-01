// renderRecs, checkRecsInLibrary, recsSpinner — provided by js/recommendations.js

document.addEventListener('DOMContentLoaded', () => {
  const recommendBtn = document.getElementById('recommendBtn');
  const recommendSection = document.getElementById('recommendSection');
  const synopsisBtn = document.getElementById('synopsisBtn');
  const descriptionInput = document.getElementById('description');
  const titleInput = document.getElementById('title');

  function setDescriptionValue(val) {
    const editor = window.tinymce?.get('description');
    if (editor) {
      editor.setContent(val);
      editor.save();
    } else if (descriptionInput) {
      descriptionInput.value = val;
    }
  }

  if (recommendSection.dataset.saved) {
    recommendSection.innerHTML = renderRecs(recommendSection.dataset.saved);
    checkRecsInLibrary(recommendSection);
  }

  const bodyData = document.body.dataset;
  const currentBookId = parseInt(bodyData.bookId, 10);

  const metadataBtn = document.getElementById('metadataBtn');
  const metadataResults = document.getElementById('metadataResults');
  const metadataModalEl = document.getElementById('metadataModal');
  let metadataModal = null;

  const ebookFile = bodyData.ebookFile;
  const extractCoverBtn = document.getElementById('extractCoverBtn');
  const coverModalEl = document.getElementById('coverModal');
  const coverImgEl = document.getElementById('extractedCoverImg');
  const coverSizeEl = document.getElementById('extractedCoverSize');
  const useCoverBtn = document.getElementById('useExtractedCover');
  let coverModal = null;
  let extractedCoverData = '';

  recommendBtn.addEventListener('click', () => {
    const bookId  = recommendBtn.dataset.bookId;
    const authors = recommendBtn.dataset.authors || '';
    const title   = recommendBtn.dataset.title   || '';
    const genres  = recommendBtn.dataset.genres  || '';
    recommendSection.innerHTML = recsSpinner;
    fetch('json_endpoints/recommend.php?book_id=' + encodeURIComponent(bookId) +
          '&authors=' + encodeURIComponent(authors) +
          '&title='   + encodeURIComponent(title) +
          '&genres='  + encodeURIComponent(genres))
      .then(resp => resp.json())
      .then(data => {
        if (data.output) {
          recommendSection.innerHTML = renderRecs(data.output);
          checkRecsInLibrary(recommendSection);
        } else {
          recommendSection.innerHTML = '<p class="text-danger">' + escapeHTML(data.error || 'Error') + '</p>';
        }
      })
      .catch(() => {
        recommendSection.innerHTML = '<p class="text-danger">Network error</p>';
      });
  });

  synopsisBtn.addEventListener('click', () => {
    const bookId = synopsisBtn.dataset.bookId;
    const authors = synopsisBtn.dataset.authors;
    const title = synopsisBtn.dataset.title;
    setDescriptionValue('Loading...');
    fetch('json_endpoints/synopsis.php?book_id=' + encodeURIComponent(bookId) +
          '&authors=' + encodeURIComponent(authors) + '&title=' + encodeURIComponent(title))
      .then(resp => resp.json())
      .then(data => {
        if (data.output) {
          setDescriptionValue(data.output);
        } else {
          setDescriptionValue(data.error || 'Error');
        }
      })
      .catch(() => {
        setDescriptionValue('Error fetching synopsis');
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
  const isbnInput = document.getElementById('isbn');
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

      // Show local preview immediately
      const previewUrl = URL.createObjectURL(file);
      img.src = previewUrl;
      img.addEventListener('load', () => {
        updateDimensions();
        URL.revokeObjectURL(previewUrl);
      }, { once: true });

      // Upload via AJAX then autosave
      const reader = new FileReader();
      reader.onload = () => {
        const base64 = reader.result.split(',')[1];
        const fd = new FormData();
        fd.append('book_id', currentBookId);
        fd.append('coverdata', base64);
        fetch('json_endpoints/update_metadata.php', {
          method: 'POST',
          headers: { 'Accept': 'application/json' },
          body: fd,
        })
          .then(r => r.json())
          .then(data => {
            if (data.status === 'ok') {
              // Refresh the displayed cover from disk (bypass browser cache)
              if (data.cover_url) {
                img.src = data.cover_url + '?t=' + Date.now();
                img.addEventListener('load', updateDimensions, { once: true });
              }
              // Clear the file input so the form submit doesn't re-process it
              coverInput.value = '';
              // Autosave all other metadata fields
              const metaForm = document.querySelector('form[method="post"][enctype]');
              if (metaForm) metaForm.requestSubmit();
            } else {
              alert(data.error || 'Error saving cover');
            }
          })
          .catch(() => alert('Error uploading cover'));
      };
      reader.readAsDataURL(file);
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
  function setupFieldAutocomplete(input, ul, endpoint, onSelect) {
    if (!input || !ul) return;
    let debounceTimer;
    let selectedIndex = -1;

    const clear = () => {
      ul.innerHTML = '';
      ul.style.display = 'none';
      selectedIndex = -1;
    };

    const render = (items) => {
      clear();
      if (!items.length) return;
      items.forEach(name => {
        const li = document.createElement('li');
        li.className = 'list-group-item list-group-item-action py-1 px-2 small';
        li.textContent = name;
        li.addEventListener('mousedown', e => {
          e.preventDefault();
          input.value = name;
          if (onSelect) onSelect(name);
          clear();
        });
        ul.appendChild(li);
      });
      ul.style.display = 'block';
    };

    input.addEventListener('input', () => {
      if (onSelect) onSelect(input.value);
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(async () => {
        const term = input.value.trim();
        if (term.length < 2) { clear(); return; }
        try {
          const res = await fetch(`json_endpoints/${endpoint}?term=${encodeURIComponent(term)}`);
          render(await res.json());
        } catch (err) { console.error(err); }
      }, 300);
    });

    input.addEventListener('keydown', e => {
      const items = ul.querySelectorAll('li');
      if (!items.length) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex = (selectedIndex + 1) % items.length;
        items.forEach((li, i) => li.classList.toggle('active', i === selectedIndex));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex = (selectedIndex - 1 + items.length) % items.length;
        items.forEach((li, i) => li.classList.toggle('active', i === selectedIndex));
      } else if (e.key === 'Enter' && selectedIndex >= 0) {
        e.preventDefault();
        input.value = items[selectedIndex].textContent;
        if (onSelect) onSelect(input.value);
        clear();
      } else if (e.key === 'Escape') {
        clear();
      }
    });

    input.addEventListener('blur', () => setTimeout(clear, 150));
  }

  setupFieldAutocomplete(
    authorInput, suggestionList,
    'author_autocomplete.php',
    () => updateAuthorSort()
  );

  if (authorSortInput && !authorSortInput.value) updateAuthorSort();

  if (applySortBtn) {
    applySortBtn.addEventListener('click', updateAuthorSort);
  }

  setupFieldAutocomplete(
    document.getElementById('title'),
    document.getElementById('titleSuggestions'),
    'title_autocomplete.php'
  );

  document.addEventListener('click', e => {
    if (suggestionList && !suggestionList.contains(e.target) && e.target !== authorInput) {
      suggestionList.style.display = 'none';
    }
    const titleSuggestions = document.getElementById('titleSuggestions');
    const titleInput = document.getElementById('title');
    if (titleSuggestions && !titleSuggestions.contains(e.target) && e.target !== titleInput) {
      titleSuggestions.style.display = 'none';
    }
  });

  const seriesInput = document.getElementById('seriesInput');
  const subseriesInput = document.getElementById('subseriesInput');
  const seriesIndexInput = document.getElementById('seriesIndex');
  const subseriesIndexInput = document.getElementById('subseriesIndex');
  const seriesSuggestions = document.getElementById('seriesSuggestions');
  const subseriesSuggestions = document.getElementById('subseriesSuggestions');

  function setupAutocomplete(input, listEl, endpoint) {
    if (!input || !listEl) return;
    let debounceTimer;
    let selectedIndex = -1;
    const clear = () => {
      listEl.innerHTML = '';
      listEl.style.display = 'none';
      selectedIndex = -1;
    };
    const render = items => {
      clear();
      if (!items.length) return;
      items.forEach(name => {
        const li = document.createElement('li');
        li.className = 'list-group-item list-group-item-action';
        li.textContent = name;
        li.addEventListener('mousedown', e => {
          e.preventDefault();
          input.value = name;
          clear();
        });
        listEl.appendChild(li);
      });
      listEl.style.display = 'block';
    };
    const fetchSuggestions = async () => {
      const term = input.value.trim();
      if (term.length < 2) { clear(); return; }
      try {
        const res = await fetch(`json_endpoints/${endpoint}?term=${encodeURIComponent(term)}`);
        const data = await res.json();
        render(data);
      } catch (err) { console.error(err); }
    };
    input.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(fetchSuggestions, 300);
    });
    input.addEventListener('keydown', e => {
      const items = listEl.querySelectorAll('li');
      if (!items.length) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex = (selectedIndex + 1) % items.length;
        items.forEach((it, idx) => it.classList.toggle('active', idx === selectedIndex));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex = (selectedIndex - 1 + items.length) % items.length;
        items.forEach((it, idx) => it.classList.toggle('active', idx === selectedIndex));
      } else if (e.key === 'Enter') {
        if (selectedIndex >= 0) {
          e.preventDefault();
          input.value = items[selectedIndex].textContent;
          clear();
        }
      } else if (e.key === 'Escape') {
        clear();
      }
    });
    document.addEventListener('click', e => {
      if (!listEl.contains(e.target) && e.target !== input) clear();
    });
  }

  setupAutocomplete(seriesInput, seriesSuggestions, 'series_autocomplete.php');
  setupAutocomplete(subseriesInput, subseriesSuggestions, 'subseries_autocomplete.php');

  // ── Write Metadata to File ─────────────────────────────────────
  const writeMetaBtn = document.getElementById('writeMetaBtn');
  if (writeMetaBtn) {
    writeMetaBtn.addEventListener('click', async () => {
      const bookId = writeMetaBtn.dataset.bookId;
      const orig = writeMetaBtn.innerHTML;
      writeMetaBtn.disabled = true;
      writeMetaBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Writing…';

      try {
        const fd = new FormData();
        fd.append('book_id', bookId);
        const res  = await fetch('json_endpoints/write_ebook_metadata.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.ok) {
          writeMetaBtn.classList.replace('btn-outline-secondary', 'btn-outline-success');
          writeMetaBtn.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> Written to ' + escapeHTML(data.format);
          writeMetaBtn.title = data.command || '';
          console.log('[write-meta] command:', data.command);
          console.log('[write-meta] output:', data.detail);
          setTimeout(() => {
            writeMetaBtn.innerHTML = orig;
            writeMetaBtn.classList.replace('btn-outline-success', 'btn-outline-secondary');
            writeMetaBtn.disabled = false;
            writeMetaBtn.title = '';
          }, 3000);
        } else {
          writeMetaBtn.classList.replace('btn-outline-secondary', 'btn-outline-danger');
          writeMetaBtn.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> Failed';
          writeMetaBtn.title = data.error + (data.detail ? '\n' + data.detail : '');
          console.error('[write-meta] error:', data.error);
          console.error('[write-meta] command:', data.command);
          console.error('[write-meta] detail:', data.detail);
          setTimeout(() => {
            writeMetaBtn.innerHTML = orig;
            writeMetaBtn.classList.replace('btn-outline-danger', 'btn-outline-secondary');
            writeMetaBtn.disabled = false;
            writeMetaBtn.title = '';
          }, 4000);
        }
      } catch (e) {
        writeMetaBtn.innerHTML = orig;
        writeMetaBtn.disabled = false;
      }
    });
  }

  // ── Send to Device (writes metadata first) ─────────────────────
  const sendToDeviceBtn    = document.getElementById('sendToDeviceBtn');
  const sendToDeviceHidden = document.getElementById('sendToDeviceHidden');
  if (sendToDeviceBtn && sendToDeviceHidden) {
    sendToDeviceBtn.addEventListener('click', async () => {
      const bookId = sendToDeviceBtn.dataset.bookId;
      const origHtml = sendToDeviceBtn.innerHTML;
      sendToDeviceBtn.disabled = true;
      sendToDeviceBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Writing metadata…';

      try {
        const fd = new FormData();
        fd.append('book_id', bookId);
        const res  = await fetch('json_endpoints/write_ebook_metadata.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) {
          console.warn('[send-to-device] metadata write failed:', data.error, data.detail);
        } else {
          console.log('[send-to-device] metadata written to', data.format);
        }
      } catch (e) {
        console.warn('[send-to-device] metadata write fetch error:', e);
      }

      // Proceed with send regardless of metadata write outcome
      sendToDeviceBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Sending…';
      sendToDeviceHidden.value = '1';
      sendToDeviceBtn.closest('form').submit();
    });
  }

  // ── Copy to Library ────────────────────────────────────────────
  const transferConfirmBtn = document.getElementById('transferConfirmBtn');
  const transferStatus     = document.getElementById('transferStatus');
  const transferTarget     = document.getElementById('transferTarget');

  if (transferConfirmBtn) {
    transferConfirmBtn.addEventListener('click', async () => {
      const targetUser = transferTarget?.value;
      if (!targetUser) return;

      const orig = transferConfirmBtn.innerHTML;
      transferConfirmBtn.disabled = true;
      transferConfirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Copying…';
      transferStatus.innerHTML = '';

      try {
        const res  = await fetch('json_endpoints/transfer_book.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ book_id: currentBookId, target_user: targetUser })
        });
        const data = await res.json();

        if (data.status === 'ok') {
          transferStatus.innerHTML =
            '<div class="alert alert-success mb-0"><i class="fa-solid fa-circle-check me-2"></i>' +
            escapeHTML(data.message) + ' (' + (data.formats || 0) + ' file' + (data.formats !== 1 ? 's' : '') + ' copied)</div>';
          transferConfirmBtn.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> Done';
        } else if (data.status === 'duplicate') {
          transferStatus.innerHTML =
            '<div class="alert alert-warning mb-0"><i class="fa-solid fa-triangle-exclamation me-2"></i>' +
            escapeHTML(data.message) + '</div>';
          transferConfirmBtn.innerHTML = orig;
          transferConfirmBtn.disabled = false;
        } else {
          transferStatus.innerHTML =
            '<div class="alert alert-danger mb-0"><i class="fa-solid fa-triangle-exclamation me-2"></i>' +
            escapeHTML(data.error || 'Unknown error') + '</div>';
          transferConfirmBtn.innerHTML = orig;
          transferConfirmBtn.disabled = false;
        }
      } catch (err) {
        transferStatus.innerHTML = '<div class="alert alert-danger mb-0">Network error</div>';
        transferConfirmBtn.innerHTML = orig;
        transferConfirmBtn.disabled = false;
      }
    });
  }

  // ── Author Tab — Save Bio ──────────────────────────────────────
  document.addEventListener('click', async e => {
    const btn = e.target.closest('.save-author-bio-btn');
    if (!btn) return;
    const authorId = btn.dataset.authorId;
    const textarea = document.querySelector(`.author-bio-editor[data-author-id="${authorId}"]`);
    const status   = document.querySelector(`.author-bio-status[data-author-id="${authorId}"]`);
    if (!textarea) return;
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving…';
    if (status) status.textContent = '';
    try {
      const res  = await fetch('json_endpoints/save_author_bio.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ author_id: authorId, bio: textarea.value })
      });
      const data = await res.json();
      if (data.status === 'ok') {
        if (status) { status.textContent = 'Saved'; setTimeout(() => { status.textContent = ''; }, 2000); }
      } else {
        if (status) status.textContent = data.error || 'Error';
      }
    } catch (err) {
      if (status) status.textContent = 'Network error';
    } finally {
      btn.innerHTML = orig;
      btn.disabled = false;
    }
  });

  const swapSeriesSubseriesBtn = document.getElementById('swapSeriesSubseriesBtn');
  if (swapSeriesSubseriesBtn && seriesInput && subseriesInput) {
    swapSeriesSubseriesBtn.addEventListener('click', () => {
      const tmpSeries = seriesInput.value;
      seriesInput.value = subseriesInput.value;
      subseriesInput.value = tmpSeries;
      if (seriesIndexInput && subseriesIndexInput) {
        const tmpIdx = seriesIndexInput.value;
        seriesIndexInput.value = subseriesIndexInput.value;
        subseriesIndexInput.value = tmpIdx;
      }
    });
  }
});
