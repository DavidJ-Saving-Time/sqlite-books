<?php
require_once '../db.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LOC Catalog Import</title>
  <link rel="stylesheet" href="/theme.css.php">
  <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
  <style>
    .stat-box { min-width: 100px; text-align: center; }
    .stat-num { font-size: 2rem; font-weight: 700; line-height: 1; }
    .review-card { border: 1px solid var(--bs-border-color); border-radius: 6px; padding: 1rem; margin-bottom: 0.75rem; }
    .review-card.accepted { opacity: 0.5; border-color: var(--bs-success); }
    .review-card.rejected { opacity: 0.5; border-color: var(--bs-secondary); }
    .score-badge { font-size: 0.9rem; font-weight: 600; min-width: 48px; text-align: center; }
    .loc-field { font-size: 0.82rem; }
    .subject-pill { font-size: 0.72rem; }
    .log-area { font-size: 0.78rem; font-family: monospace; max-height: 220px; overflow-y: auto;
                background: var(--bs-secondary-bg); border-radius: 4px; padding: 0.5rem; }
    .log-auto { color: var(--bs-success); }
    .log-review { color: var(--bs-warning); }
    .log-miss { color: var(--bs-secondary-color); }
    .log-err { color: var(--bs-danger); }
  </style>
</head>
<body>
<?php include '../navbar.php'; ?>
<div class="container-fluid" style="padding-top:5rem; max-width:1100px">

  <div class="d-flex align-items-center mb-3 gap-3">
    <h4 class="mb-0"><i class="fa-solid fa-landmark me-2"></i>Library of Congress — Bulk Import</h4>
    <div class="ms-auto d-flex gap-2">
      <button id="btnStart" class="btn btn-primary"><i class="fa-solid fa-play me-1"></i>Start</button>
      <button id="btnStop"  class="btn btn-danger d-none"><i class="fa-solid fa-stop me-1"></i>Stop</button>
      <button id="btnWipe"  class="btn btn-outline-secondary ms-2" title="Delete all loc_checked markers so books are re-queried">
        <i class="fa-solid fa-rotate-left me-1"></i>Reset checked
      </button>
    </div>
  </div>

  <p class="text-muted small mb-3">
    Queries the LOC Z39.50 catalog for every book not yet checked.
    High-confidence matches (≥70/120) are applied automatically.
    Uncertain matches (40–69) are shown below for review.
    Results saved: LCCN, ISBN, LCC call number, LCSH subjects, genre headings.
  </p>

  <!-- Progress -->
  <div class="progress mb-3" style="height:6px">
    <div id="progressBar" class="progress-bar" style="width:0%"></div>
  </div>

  <!-- Stats row -->
  <div class="d-flex gap-3 flex-wrap mb-4">
    <div class="stat-box border rounded p-2">
      <div class="stat-num text-primary" id="statTotal">–</div>
      <div class="small text-muted">To check</div>
    </div>
    <div class="stat-box border rounded p-2">
      <div class="stat-num text-success" id="statAuto">0</div>
      <div class="small text-muted">Auto-applied</div>
    </div>
    <div class="stat-box border rounded p-2">
      <div class="stat-num text-warning" id="statReview">0</div>
      <div class="small text-muted">Review queue</div>
    </div>
    <div class="stat-box border rounded p-2">
      <div class="stat-num text-secondary" id="statMiss">0</div>
      <div class="small text-muted">Not found</div>
    </div>
    <div class="stat-box border rounded p-2">
      <div class="stat-num text-danger" id="statErr">0</div>
      <div class="small text-muted">Errors</div>
    </div>
    <div class="stat-box border rounded p-2">
      <div class="stat-num" id="statN">–</div>
      <div class="small text-muted">Current</div>
    </div>
  </div>

  <!-- Live log (collapsible) -->
  <details class="mb-4">
    <summary class="text-muted small mb-1" style="cursor:pointer">Live log</summary>
    <div class="log-area" id="logArea"></div>
  </details>

  <!-- Review queue -->
  <div id="reviewSection" class="d-none">
    <h5 class="mb-3"><i class="fa-solid fa-magnifying-glass me-2 text-warning"></i>Review Queue
      <span class="badge bg-warning text-dark ms-2" id="reviewCount">0</span>
    </h5>
    <div id="reviewList"></div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="/js/search.js"></script>
<script>
(function () {
    const btnStart   = document.getElementById('btnStart');
    const btnStop    = document.getElementById('btnStop');
    const btnWipe    = document.getElementById('btnWipe');
    const progress   = document.getElementById('progressBar');
    const logArea    = document.getElementById('logArea');
    const reviewSec  = document.getElementById('reviewSection');
    const reviewList = document.getElementById('reviewList');
    const revCount   = document.getElementById('reviewCount');

    const stat = id => document.getElementById(id);
    let reviewTotal = 0;
    let source = null;
    let total  = 0;

    function log(msg, cls = '') {
        const d = document.createElement('div');
        if (cls) d.className = cls;
        d.textContent = msg;
        logArea.appendChild(d);
        logArea.scrollTop = logArea.scrollHeight;
    }

    function setProgress(n, t) {
        const pct = t > 0 ? Math.round(n / t * 100) : 0;
        progress.style.width = pct + '%';
        stat('statN').textContent = t > 0 ? `${n}/${t}` : '–';
    }

    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function scoreClass(s) {
        return s >= 70 ? 'bg-success' : (s >= 40 ? 'bg-warning text-dark' : 'bg-danger');
    }

    function renderReviewCard(d) {
        reviewSec.classList.remove('d-none');
        reviewTotal++;
        revCount.textContent = reviewTotal;

        const card = document.createElement('div');
        card.className = 'review-card';
        card.dataset.bookId = d.book_id;

        const isbns    = (d.isbns    || []).slice(0, 4).join(', ');
        const subjects = (d.subjects || []).slice(0, 5).map(s =>
            `<span class="badge bg-secondary subject-pill me-1">${esc(s)}</span>`).join('');
        const genres   = (d.genres   || []).filter(g => g.length > 6).slice(0, 4).map(g =>
            `<span class="badge bg-info text-dark subject-pill me-1">${esc(g)}</span>`).join('');

        card.innerHTML = `
          <div class="d-flex gap-3 align-items-start">
            <!-- Score -->
            <div class="flex-shrink-0 text-center">
              <span class="badge ${scoreClass(d.score)} score-badge">${d.score}</span>
              <div class="text-muted" style="font-size:0.7rem">/ 120</div>
            </div>
            <!-- Our book -->
            <div class="flex-grow-1">
              <div class="row g-3">
                <div class="col-md-5">
                  <div class="small text-muted fw-semibold mb-1">OUR LIBRARY</div>
                  <div class="fw-semibold">${esc(d.title)}</div>
                  <div class="text-muted small">${esc(d.author)}</div>
                  <a href="../book.php?id=${d.book_id}" target="_blank" class="small text-muted">
                    <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i> Edit
                  </a>
                </div>
                <div class="col-md-7">
                  <div class="small text-muted fw-semibold mb-1">LOC CATALOG MATCH</div>
                  <div class="fw-semibold">${esc(d.loc_title)}</div>
                  <div class="text-muted small">${esc(d.loc_author)}</div>
                  <div class="loc-field text-muted">
                    ${d.publisher ? esc(d.publisher) + (d.date ? ', ' + esc(d.date) : '') : (d.date || '')}
                    ${d.edition   ? ' · <em>' + esc(d.edition) + '</em>' : ''}
                  </div>
                  ${d.lccn ? `<div class="loc-field">LCCN: <a href="https://lccn.loc.gov/${esc(d.lccn)}" target="_blank" rel="noopener">${esc(d.lccn)}</a></div>` : ''}
                  ${d.lcc  ? `<div class="loc-field text-muted">LCC: ${esc(d.lcc)}</div>` : ''}
                  ${isbns  ? `<div class="loc-field text-muted">ISBN: ${esc(isbns)}</div>` : ''}
                  ${subjects || genres ? `<div class="mt-1">${genres}${subjects}</div>` : ''}
                </div>
              </div>
              <!-- Actions -->
              <div class="mt-2 d-flex gap-2">
                <button class="btn btn-sm btn-outline-success js-accept" data-book-id="${d.book_id}">
                  <i class="fa-solid fa-check me-1"></i>Accept
                </button>
                <button class="btn btn-sm btn-outline-secondary js-reject" data-book-id="${d.book_id}">
                  <i class="fa-solid fa-xmark me-1"></i>Skip
                </button>
              </div>
            </div>
          </div>`;

        // Store LOC data on the card for the Accept action
        card.dataset.locData = JSON.stringify({
            book_id:  d.book_id,
            lccn:     d.lccn     || '',
            lcc:      d.lcc      || '',
            isbns:    d.isbns    || [],
            subjects: d.subjects || [],
            genres:   d.genres   || [],
        });

        reviewList.appendChild(card);
    }

    // Accept / Reject handlers (delegated)
    reviewList.addEventListener('click', e => {
        const acceptBtn = e.target.closest('.js-accept');
        const rejectBtn = e.target.closest('.js-reject');
        if (!acceptBtn && !rejectBtn) return;

        const card    = (acceptBtn || rejectBtn).closest('.review-card');
        const locData = JSON.parse(card.dataset.locData || '{}');
        const btn     = acceptBtn || rejectBtn;
        btn.disabled  = true;

        if (acceptBtn) {
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            fetch('../json_endpoints/loc_apply.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(locData),
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    card.classList.add('accepted');
                    btn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Accepted';
                    card.querySelector('.js-reject')?.remove();
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-xmark me-1"></i>Error';
                }
            })
            .catch(() => { btn.disabled = false; btn.innerHTML = 'Error'; });
        } else {
            // Reject: mark as loc_checked without saving LOC data
            fetch('../json_endpoints/loc_apply.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ book_id: locData.book_id }), // save only loc_checked
            })
            .then(() => {
                card.classList.add('rejected');
                btn.innerHTML = '<i class="fa-solid fa-xmark me-1"></i>Skipped';
                card.querySelector('.js-accept')?.remove();
            });
        }
    });

    btnStart.addEventListener('click', () => {
        btnStart.classList.add('d-none');
        btnStop.classList.remove('d-none');
        logArea.innerHTML = '';
        reviewList.innerHTML = '';
        reviewSec.classList.add('d-none');
        reviewTotal = 0;
        revCount.textContent = '0';
        ['statAuto','statReview','statMiss','statErr'].forEach(id => stat(id).textContent = '0');
        stat('statTotal').textContent = '–';
        progress.style.width = '0%';

        source = new EventSource('../json_endpoints/loc_import_stream.php');

        source.addEventListener('scan_done', e => {
            const d = JSON.parse(e.data);
            total = d.total;
            stat('statTotal').textContent = total;
            log(`Scanning ${total} books…`);
        });

        source.addEventListener('auto_applied', e => {
            const d = JSON.parse(e.data);
            stat('statAuto').textContent = parseInt(stat('statAuto').textContent) + 1;
            setProgress(d.n, total);
            log(`✓ [${d.score}] ${d.title} → LCCN ${d.lccn || '?'}`, 'log-auto');
        });

        source.addEventListener('review_needed', e => {
            const d = JSON.parse(e.data);
            stat('statReview').textContent = parseInt(stat('statReview').textContent) + 1;
            setProgress(d.n, total);
            log(`? [${d.score}] ${d.title} — needs review`, 'log-review');
            renderReviewCard(d);
        });

        source.addEventListener('not_found', e => {
            const d = JSON.parse(e.data);
            stat('statMiss').textContent = parseInt(stat('statMiss').textContent) + 1;
            setProgress(d.n, total);
            log(`– ${d.title}`, 'log-miss');
        });

        source.addEventListener('skipped', e => {
            const d = JSON.parse(e.data);
            setProgress(d.n, total);
        });

        source.addEventListener('error', e => {
            try {
                const d = JSON.parse(e.data);
                stat('statErr').textContent = parseInt(stat('statErr').textContent) + 1;
                log(`✗ ${d.title}: ${d.message}`, 'log-err');
            } catch {
                // SSE connection error — stream ended or stopped
                source?.close();
                btnStart.classList.remove('d-none');
                btnStop.classList.add('d-none');
            }
        });

        source.addEventListener('status', e => {
            const d = JSON.parse(e.data);
            log(d.message);
        });

        source.addEventListener('done', e => {
            const d = JSON.parse(e.data);
            source.close();
            source = null;
            btnStart.classList.remove('d-none');
            btnStop.classList.add('d-none');
            progress.style.width = '100%';
            log(`Done — auto: ${d.auto_applied}, review: ${d.review_needed}, not found: ${d.not_found}, errors: ${d.errors}`);
        });
    });

    btnWipe.addEventListener('click', () => {
        if (!confirm('Delete all loc_checked markers? Books will be re-queried on the next run.')) return;
        btnWipe.disabled = true;
        btnWipe.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        fetch('../json_endpoints/loc_wipe_checked.php')
            .then(r => r.json())
            .then(res => {
                btnWipe.disabled = false;
                btnWipe.innerHTML = '<i class="fa-solid fa-rotate-left me-1"></i>Reset checked';
                if (res.success) {
                    alert(`Cleared ${res.deleted} loc_checked entries.`);
                } else {
                    alert('Error: ' + (res.error || 'unknown'));
                }
            })
            .catch(() => {
                btnWipe.disabled = false;
                btnWipe.innerHTML = '<i class="fa-solid fa-rotate-left me-1"></i>Reset checked';
            });
    });

    btnStop.addEventListener('click', () => {
        fetch('../json_endpoints/loc_import_stop.php');
        btnStop.disabled = true;
        btnStop.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Stopping…';
    });
}());
</script>
</body>
</html>
