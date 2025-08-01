<?php
$searchVal = isset($search) ? $search : '';
$sortVal = isset($sort) ? $sort : 'title';
$sourceVal = isset($source) ? $source : 'local';
$action = 'list_books.php';
if ($sourceVal === 'openlibrary') {
    $action = 'openlibrary_results.php';
} elseif ($sourceVal === 'google') {
    $action = 'google_results.php';
} elseif ($sourceVal === 'annas') {
    $action = 'annas_results.php';
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
      <button class="btn btn-primary me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu">
        <i class="fa-solid fa-bars"></i>
      </button>
      <a class="navbar-brand d-flex align-items-center" href="list_books.php">
        <i class="fa-solid fa-book-open me-2"></i> Books
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

        <!-- Search Form -->
        <form class="d-flex me-3" method="get" action="<?= htmlspecialchars($action) ?>">
          <input type="hidden" name="page" value="1">
          <input type="hidden" name="sort" value="<?= htmlspecialchars($sortVal) ?>">
          <?php if ($authorIdVal): ?><input type="hidden" name="author_id" value="<?= htmlspecialchars($authorIdVal) ?>"><?php endif; ?>
          <?php if ($seriesIdVal): ?><input type="hidden" name="series_id" value="<?= htmlspecialchars($seriesIdVal) ?>"><?php endif; ?>
          <?php if ($genreIdVal): ?><input type="hidden" name="genre_id" value="<?= htmlspecialchars($genreIdVal) ?>"><?php endif; ?>
          <?php if ($shelfNameVal !== ''): ?><input type="hidden" name="shelf" value="<?= htmlspecialchars($shelfNameVal) ?>"><?php endif; ?>
          <?php if ($statusNameVal !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusNameVal) ?>"><?php endif; ?>

          <div class="input-group">
            <input class="form-control" type="search" name="search" style="width: 20rem;" placeholder="Search books..." value="<?= htmlspecialchars($searchVal) ?>" aria-label="Search" list="authorSuggestions">
            <datalist id="authorSuggestions"></datalist>
            <select name="source" class="form-select" style="max-width: 12rem;">
              <option value="local"<?= $sourceVal === 'local' ? ' selected' : '' ?>>Local</option>
              <option value="openlibrary"<?= $sourceVal === 'openlibrary' ? ' selected' : '' ?>>Open Library</option>
              <option value="google"<?= $sourceVal === 'google' ? ' selected' : '' ?>>Google Books</option>
              <option value="annas"<?= $sourceVal === 'annas' ? ' selected' : '' ?>>Anna's Archive</option>
            </select>
            <button class="btn btn-primary" type="submit">
              <i class="fa-solid fa-magnifying-glass"></i> Search
            </button>
          </div>
        </form>

        <!-- Sort Form -->
        <form class="d-flex" method="get" action="<?= htmlspecialchars($action) ?>">
          <input type="hidden" name="page" value="1">
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
              <option value="recommended"<?= $sortVal === 'recommended' ? ' selected' : '' ?>>Recommended Only</option>
            </select>
          </div>
        </form>
      </div>

      <!-- Right: Navigation Links + User -->
      <ul class="navbar-nav ms-3 align-items-center">

        <!-- Add Book Button -->
        <li class="nav-item me-2">
          <a class="btn btn-primary" href="add_physical_book.php">
            <i class="fa-solid fa-plus me-1"></i> Add Book
          </a>
        </li>

        <!-- WordPro Button -->
        <li class="nav-item me-2">
          <a class="btn btn-primary" href="/notepad.php">
            <i class="fa-solid fa-pen-nib me-1"></i> WordPro
          </a>
        </li>
        
                <!-- WordPro Button -->
        <li class="nav-item me-2">
          <a class="btn btn-primary" href="/ircdashboard.php">
            <i class="fa fa-terminal me-1"></i>IRC
          </a>
        </li>

        <!-- Shelf Selector -->
        <?php if (isset($shelfList) && function_exists('buildBaseUrl')): ?>
        <li class="nav-item">
          <form class="d-flex" method="get" action="<?= htmlspecialchars($action) ?>">
            <input type="hidden" name="page" value="1">
            <?php if ($searchVal !== ''): ?><input type="hidden" name="search" value="<?= htmlspecialchars($searchVal) ?>"><?php endif; ?>
            <?php if ($authorIdVal): ?><input type="hidden" name="author_id" value="<?= htmlspecialchars($authorIdVal) ?>"><?php endif; ?>
            <?php if ($seriesIdVal): ?><input type="hidden" name="series_id" value="<?= htmlspecialchars($seriesIdVal) ?>"><?php endif; ?>
            <?php if ($genreIdVal): ?><input type="hidden" name="genre_id" value="<?= htmlspecialchars($genreIdVal) ?>"><?php endif; ?>
            <?php if ($statusNameVal !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusNameVal) ?>"><?php endif; ?>
            <?php if ($sortVal !== ''): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sortVal) ?>"><?php endif; ?>

            <div class="input-group">
              <span class="input-group-text"><i class="fa-solid fa-layer-group"></i></span>
              <select class="form-select" name="shelf" id="shelfSelect">
                <option value=""<?= $shelfNameVal === '' ? ' selected' : '' ?>>All Shelves</option>
                <?php foreach ($shelfList as $s): ?>
                  <option value="<?= htmlspecialchars($s) ?>"<?= $shelfNameVal === $s ? ' selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>
        </li>
        <?php endif; ?>

        <!-- User Dropdown -->
        <?php if (currentUser()): ?>
        <li class="nav-item dropdown ms-3">
          <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fa-solid fa-user me-1"></i>
            <?= htmlspecialchars(currentUser()) ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
            <li>
              <a class="dropdown-item" href="reading_challenges.php">
                <i class="fa-solid fa-flag-checkered me-1"></i> Reading Challenge
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="preferences.php">
                <i class="fa-solid fa-gear me-1"></i> Prefs
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item" href="logout.php">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
              </a>
            </li>
          </ul>
        </li>
        <?php else: ?>
        <li class="nav-item ms-3">
          <a class="btn btn-sm btn-outline-light" href="login.php">
            <i class="fa-solid fa-right-to-bracket me-1"></i> Login
          </a>
        </li>
        <?php endif; ?>

      </ul>
    </div>
  </div>
</nav>


<script src="js/navbar.js"></script>
