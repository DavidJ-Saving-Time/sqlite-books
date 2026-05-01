<?php
$searchVal = isset($search) ? $search : '';
$sortVal = isset($sort) ? $sort : 'title';
$sourceVal = isset($source) ? $source : 'local';
$action = '/list_books.php';
if ($sourceVal === 'openlibrary') {
    $action = '/openlibrary_results.php';
} elseif ($sourceVal === 'google') {
    $action = '/google_results.php';
} elseif ($sourceVal === 'annas') {
    $action = '/annas_results.php';
}
$authorIdVal = isset($authorId) ? $authorId : null;
$seriesIdVal = isset($seriesId) ? $seriesId : null;
$genreIdVal = isset($genreId) ? $genreId : null;
$shelfNameVal = isset($shelfName) ? $shelfName : '';
$statusNameVal = isset($statusName) ? $statusName : '';
?>
<nav class="navbar navbar-expand-lg fixed-top navbar-dark bg-dark mb-4">
  <div class="container-fluid">

    <!-- Left: Menu Button + Brand -->
    <div class="d-flex align-items-center">
      <?php if (!empty($showOffcanvas)): ?>
      <button class="btn btn-primary me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu">
        <i class="fa-solid fa-bars"></i>
      </button>
      <?php endif; ?>
      <a class="navbar-brand d-flex align-items-center" href="/list_books.php">
      <i class="fa-duotone fa-regular fa-house-user me-2 ms-2"></i>
      </a>
    </div>

    <!-- Navbar Toggler (for mobile view) -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Navbar Content -->
    <div class="collapse navbar-collapse" id="navbarContent">

      <!-- Center: Search + Sort -->
      <div class="d-flex flex-grow-1 justify-content-center align-items-center">

        <!-- Search Form — always searches clean, no inherited filters -->
        <form class="d-flex me-3" method="get" action="/list_books.php">
          <input type="hidden" name="page" value="1">
          <input type="hidden" name="sort" value="<?= htmlspecialchars($sortVal) ?>">

          <div class="input-group position-relative">
            <input class="form-control" type="search" name="search" style="width: 20rem;" placeholder="Search books..." value="<?= htmlspecialchars($searchVal) ?>" aria-label="Search" autocomplete="off">
            <select name="source" class="form-select" style="max-width: 12rem;">
              <option value="local"<?= $sourceVal === 'local' ? ' selected' : '' ?>>Local</option>
              <option value="openlibrary"<?= $sourceVal === 'openlibrary' ? ' selected' : '' ?>>Open Library</option>
              <option value="google"<?= $sourceVal === 'google' ? ' selected' : '' ?>>Google Books</option>
              <option value="annas"<?= $sourceVal === 'annas' ? ' selected' : '' ?>>Anna's Archive</option>
            </select>
            <button class="btn btn-primary" type="submit">
              <i class="fa-solid fa-magnifying-glass"></i> Search
            </button>
            <ul id="searchSuggestions" class="list-group position-absolute w-100" style="z-index:1000; display:none; top:100%; left:0;"></ul>
          </div>
        </form>

        <!-- Sort Form -->
        <form class="d-flex me-2" method="get" action="<?= htmlspecialchars($action) ?>">
          <?php if ($searchVal !== ''): ?><input type="hidden" name="search" value="<?= htmlspecialchars($searchVal) ?>"><?php endif; ?>
          <?php if ($authorIdVal): ?><input type="hidden" name="author_id" value="<?= htmlspecialchars($authorIdVal) ?>"><?php endif; ?>
          <?php if ($seriesIdVal): ?><input type="hidden" name="series_id" value="<?= htmlspecialchars($seriesIdVal) ?>"><?php endif; ?>
          <?php if ($genreIdVal): ?><input type="hidden" name="genre_id" value="<?= htmlspecialchars($genreIdVal) ?>"><?php endif; ?>
          <?php if ($shelfNameVal !== ''): ?><input type="hidden" name="shelf" value="<?= htmlspecialchars($shelfNameVal) ?>"><?php endif; ?>
          <?php if ($statusNameVal !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusNameVal) ?>"><?php endif; ?>

          <div class="input-group">
            <span class="input-group-text"><i class="fa-solid fa-arrow-down-a-z"></i></span>
            <select class="form-select" name="sort" id="sortSelect">
              <option value="title"<?= $sortVal === 'title' ? ' selected' : '' ?>>Title</option>
              <option value="author"<?= $sortVal === 'author' ? ' selected' : '' ?>>Author</option>
              <option value="series"<?= $sortVal === 'series' ? ' selected' : '' ?>>Series</option>
              <option value="author_series"<?= $sortVal === 'author_series' ? ' selected' : '' ?>>Author &amp; Series</option>
              <option value="author_series_surname"<?= $sortVal === 'author_series_surname' ? ' selected' : '' ?>>Author &amp; Series Surname</option>
              <option value="last_modified"<?= $sortVal === 'last_modified' ? ' selected' : '' ?>>Last Updated (DB)</option>
              <option value="file_modified"<?= $sortVal === 'file_modified' ? ' selected' : '' ?>>Last Updated (File)</option>
              <option value="recommended"<?= $sortVal === 'recommended' ? ' selected' : '' ?>>Recommended Only</option>
            </select>
          </div>
        </form>

        <!-- Status Selector (only on list_books.php) -->
        <?php if (isset($statusOptions) && function_exists('buildBaseUrl')): ?>
        <form class="d-flex" method="get" action="<?= htmlspecialchars($action) ?>">
          <input type="hidden" name="page" value="1">
          <?php if ($searchVal !== ''): ?><input type="hidden" name="search" value="<?= htmlspecialchars($searchVal) ?>"><?php endif; ?>
          <?php if ($authorIdVal): ?><input type="hidden" name="author_id" value="<?= htmlspecialchars($authorIdVal) ?>"><?php endif; ?>
          <?php if ($seriesIdVal): ?><input type="hidden" name="series_id" value="<?= htmlspecialchars($seriesIdVal) ?>"><?php endif; ?>
          <?php if ($genreIdVal): ?><input type="hidden" name="genre_id" value="<?= htmlspecialchars($genreIdVal) ?>"><?php endif; ?>
          <?php if ($shelfNameVal !== ''): ?><input type="hidden" name="shelf" value="<?= htmlspecialchars($shelfNameVal) ?>"><?php endif; ?>
          <?php if ($sortVal !== ''): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sortVal) ?>"><?php endif; ?>

          <div class="input-group">
            <span class="input-group-text"><i class="fa-solid fa-list-check"></i></span>
            <select class="form-select" name="status" id="statusSelect">
              <option value=""<?= $statusNameVal === '' ? ' selected' : '' ?>>All Status</option>
              <?php foreach ($statusOptions as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>"<?= $statusNameVal === $s ? ' selected' : '' ?>><?= htmlspecialchars($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
        <?php endif; ?>
      </div>

      <!-- Right: Navigation Links + User -->
      <ul class="navbar-nav ms-3 align-items-center">

        <!-- PWA Install Button (hidden until beforeinstallprompt fires) -->
        <li class="nav-item me-2" id="pwa-install-btn" style="display:none">
          <button class="btn btn-outline-light btn-sm" onclick="installPWA()" title="Install as app">
            <i class="fa-solid fa-download me-1"></i> Install App
          </button>
        </li>

        <!-- Add Books Button -->
        <li class="nav-item me-2">
          <a class="btn btn-primary" href="/add_physical_books.php">
            <i class="fa-solid fa-plus me-1"></i> Add Books
          </a>
        </li>

        <!-- Research Dropdown -->
        <li class="nav-item dropdown me-2">
          <a class="nav-link dropdown-toggle text-white" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fa-solid fa-flask me-1"></i> Research
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li>
              <a class="dropdown-item" href="/notes/">
                <i class="fa-solid fa-pen-nib me-2"></i> WordPro
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item" href="/research/research-search.php">
                <i class="fa-solid fa-magnifying-glass me-2"></i> Search
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/research/research-ask.php">
                <i class="fa-solid fa-comments me-2"></i> Ask
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/research/research-ai.php">
                <i class="fa-solid fa-upload me-2"></i> Ingest
              </a>
            </li>
          </ul>
        </li>

        <!-- IRC Dropdown -->
        <li class="nav-item dropdown me-2">
          <a class="nav-link dropdown-toggle text-white" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fa-solid fa-terminal me-1"></i> IRC
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li>
              <a class="dropdown-item" href="/ircdashboard.php">
                <i class="fa-solid fa-gauge me-2"></i> Dashboard
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/irc_search.php">
                <i class="fa-solid fa-magnifying-glass me-2"></i> Search
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/similar_authors.php">
                <i class="fa-solid fa-users me-2"></i> Similar Authors
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item" href="/missing_by_author.php">
                <i class="fa-solid fa-user-magnifying-glass me-2"></i> Missing by Author
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/missing_by_award.php">
                <i class="fa-solid fa-trophy me-2"></i> Missing by Award
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item" href="/semantic_search.php">
                <i class="fa-solid fa-tags me-2"></i> Semantic Search
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/olsearch.php">
                <i class="fa-solid fa-database me-2"></i> Local OL Search
              </a>
            </li>
          </ul>
        </li>

        <!-- User Dropdown -->
        <?php if (currentUser()): ?>
        <li class="nav-item dropdown ms-3">
          <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fa-solid fa-user me-1"></i>
            <?= htmlspecialchars(currentUser()) ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
            <li>
              <a class="dropdown-item" href="/reading_challenges.php">
                <i class="fa-solid fa-flag-checkered me-1"></i> Reading Challenge
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/preferences.php">
                <i class="fa-solid fa-gear me-1"></i> Prefs
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/admin/ol_import.php">
                <i class="fa-solid fa-cloud-arrow-down me-1"></i> OL Import
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/admin/gr_import.php">
                <i class="fa-brands fa-goodreads me-1"></i> GR Import
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/admin/gr_similar_import.php">
                <i class="fa-solid fa-list-ul me-1"></i> Similar Books Scraper
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/admin/loc_import.php">
                <i class="fa-solid fa-landmark me-1"></i> LOC Import
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/admin/wikipedia_import.php">
                <i class="fa-brands fa-wikipedia-w me-1"></i> Wikipedia Import
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/admin/gr_spot_check.php">
                <i class="fa-solid fa-magnifying-glass me-1"></i> GR Spot Check
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/themes.php">
                <i class="fa-solid fa-palette me-1"></i> Themes
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/authors.php" target="_blank">
                <i class="fa-solid fa-gear me-1"></i> Author List
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/admin/dedup_authors.php">
                <i class="fa-solid fa-users-between-lines me-1"></i> Dedup Authors
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/admin/dedup_books.php">
                <i class="fa-solid fa-clone me-1"></i> Dedup Books
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/series.php" target="_blank">
                <i class="fa-solid fa-gear me-1"></i> Series List
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/awards.php" target="_blank">
                <i class="fa-solid fa-trophy me-1"></i> Awards
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/admin/awards_import.php" target="_blank">
                <i class="fa-solid fa-file-import me-1"></i> Awards Import
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/sync.php">
                <i class="fa-solid fa-rotate me-1"></i> Sync
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item" href="/logout.php">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
              </a>
            </li>
          </ul>
        </li>
        <?php else: ?>
        <li class="nav-item ms-3">
          <a class="btn btn-sm btn-outline-light" href="/login.php">
            <i class="fa-solid fa-right-to-bracket me-1"></i> Login
          </a>
        </li>
        <?php endif; ?>

      </ul>
    </div>
  </div>
</nav>

<script src="/js/navbar.js"></script>
<script>
// PWA: service worker + install button
(function () {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js')
            .then(reg => console.log('[SW] registered, scope:', reg.scope))
            .catch(err => console.error('[SW] registration failed:', err));
    }

    let deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', e => {
        e.preventDefault();
        deferredPrompt = e;
        const btn = document.getElementById('pwa-install-btn');
        if (btn) btn.style.removeProperty('display');
        console.log('[PWA] beforeinstallprompt captured');
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        const btn = document.getElementById('pwa-install-btn');
        if (btn) btn.style.display = 'none';
        console.log('[PWA] app installed');
    });

    window.installPWA = function () {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(choice => {
            console.log('[PWA] user choice:', choice.outcome);
            deferredPrompt = null;
        });
    };
}());
</script>
