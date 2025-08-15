<?php // Research section navbar ?>
<nav class="navbar navbar-expand-lg fixed-top navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center" href="/research.php">
      <i class="fa-solid fa-flask me-2"></i> Research
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarResearch" aria-controls="navbarResearch" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarResearch">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="/research.php">Search</a></li>
        <li class="nav-item"><a class="nav-link" href="/research/research-ask.php">Ask</a></li>
        <li class="nav-item"><a class="nav-link" href="/research/research-cite.php">Cite</a></li>
        <li class="nav-item"><a class="nav-link" href="/research/research-ai.php">AI Ingest</a></li>
        <li class="nav-item"><a class="nav-link" href="/research/research-verifyPDF.php">Verify PDF</a></li>
      </ul>
      <ul class="navbar-nav ms-auto align-items-center">
        <li class="nav-item me-2">
          <a class="btn btn-primary" href="/add_physical_book.php">
            <i class="fa-solid fa-plus me-1"></i> Add Book
          </a>
        </li>
        <li class="nav-item me-2">
          <a class="btn btn-primary" href="/add_physical_books.php">
            <i class="fa-solid fa-plus me-1"></i> Add Books
          </a>
        </li>
        <li class="nav-item me-2">
          <a class="btn btn-primary" href="/notes/">
            <i class="fa-solid fa-pen-nib me-1"></i> WordPro
          </a>
        </li>
        <?php if (function_exists('currentUser') && currentUser()): ?>
        <li class="nav-item dropdown">
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
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item" href="/logout.php">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
              </a>
            </li>
          </ul>
        </li>
        <?php else: ?>
        <li class="nav-item">
          <a class="btn btn-sm btn-outline-light" href="/login.php">
            <i class="fa-solid fa-right-to-bracket me-1"></i> Login
          </a>
        </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<script src="../js/navbar.js"></script>
