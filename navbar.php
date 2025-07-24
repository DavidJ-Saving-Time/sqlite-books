<nav class="navbar navbar-expand-lg fixed-top navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="list_books.php">Books</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarContent">
      <!-- Search & Source -->
      <form class="d-flex ms-lg-3 " method="get" action="list_books.php">
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sortVal) ?>">
        <?php if ($authorIdVal): ?><input type="hidden" name="author_id" value="<?= htmlspecialchars($authorIdVal) ?>"><?php endif; ?>
        <?php if ($seriesIdVal): ?><input type="hidden" name="series_id" value="<?= htmlspecialchars($seriesIdVal) ?>"><?php endif; ?>
        <?php if ($genreIdVal): ?><input type="hidden" name="genre_id" value="<?= htmlspecialchars($genreIdVal) ?>"><?php endif; ?>
        <?php if ($shelfNameVal !== ''): ?><input type="hidden" name="shelf" value="<?= htmlspecialchars($shelfNameVal) ?>"><?php endif; ?>
        <?php if ($statusNameVal !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusNameVal) ?>"><?php endif; ?>

        <input class="form-control form-control-sm" type="search" name="search" placeholder="Search books..." value="<?= htmlspecialchars($searchVal) ?>" aria-label="Search">
        <select name="source" class="form-select form-select-sm" style="max-width: 12rem;">
          <option value="local"<?= $sourceVal === 'local' ? ' selected' : '' ?>>Local</option>
          <option value="openlibrary"<?= $sourceVal === 'openlibrary' ? ' selected' : '' ?>>Open Library</option>
          <option value="annas"<?= $sourceVal === 'annas' ? ' selected' : '' ?>>Anna's Archive</option>
        </select>
        <button class="btn btn-sm btn-primary" type="submit">Search</button>
      </form>

      <!-- Sort -->
      <form class="d-flex ms-lg-3" method="get" action="list_books.php">
        <input type="hidden" name="page" value="1">
        <?php if ($searchVal !== ''): ?><input type="hidden" name="search" value="<?= htmlspecialchars($searchVal) ?>"><?php endif; ?>
        <?php if ($authorIdVal): ?><input type="hidden" name="author_id" value="<?= htmlspecialchars($authorIdVal) ?>"><?php endif; ?>
        <?php if ($seriesIdVal): ?><input type="hidden" name="series_id" value="<?= htmlspecialchars($seriesIdVal) ?>"><?php endif; ?>
        <?php if ($genreIdVal): ?><input type="hidden" name="genre_id" value="<?= htmlspecialchars($genreIdVal) ?>"><?php endif; ?>
        <?php if ($shelfNameVal !== ''): ?><input type="hidden" name="shelf" value="<?= htmlspecialchars($shelfNameVal) ?>"><?php endif; ?>
        <?php if ($statusNameVal !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusNameVal) ?>"><?php endif; ?>

        <select class="form-select form-select-sm" name="sort" onchange="this.form.submit()">
          <option value="title"<?= $sortVal === 'title' ? ' selected' : '' ?>>Title</option>
          <option value="author"<?= $sortVal === 'author' ? ' selected' : '' ?>>Author</option>
          <option value="series"<?= $sortVal === 'series' ? ' selected' : '' ?>>Series</option>
          <option value="author_series"<?= $sortVal === 'author_series' ? ' selected' : '' ?>>Author & Series</option>
          <option value="recommended"<?= $sortVal === 'recommended' ? ' selected' : '' ?>>Recommended Only</option>
        </select>
      </form>

<form  class="d-flex ms-lg-3"> 
<select id="themeSelect" class="form-select form-select-sm" style="min-width: 10rem;"></select>
</form>

      <!-- Links -->
      <ul class="navbar-nav ms-3">
        <li class="nav-item">
          <a class="nav-link" href="reading_challenges.php">Reading Challenge</a>
      </ul>
    </div>
  </div>
</nav>

