
<nav class="navbar navbar-expand-lg fixed-top navbar-dark bg-dark mb-4">
  <div class="container-fluid">

    <!-- Left: Brand -->
    <a class="navbar-brand d-flex align-items-center" href="list_books.php">
      <i class="fa-solid fa-book-open me-2"></i> Books
    </a>

    <!-- Mobile Toggler -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Collapsible Content -->
    <div class="collapse navbar-collapse" id="navbarContent">

      <!-- Spacer to push right-side items -->
      <div class="flex-grow-1"></div>

      <!-- Right: Buttons + User -->
      <ul class="navbar-nav align-items-center">

        <!-- Add Book Button -->
        <li class="nav-item me-2">
          <a class="btn btn-primary" href="add_physical_book.php">
            <i class="fa-solid fa-plus me-1"></i> Add Book
          </a>
        </li>

        <!-- Add Multiple Books Button -->
        <li class="nav-item me-2">
          <a class="btn btn-primary" href="add_physical_books.php">
            <i class="fa-solid fa-plus me-1"></i> Add Books
          </a>
        </li>

        <!-- WordPro Button -->
        <li class="nav-item me-2">
          <a class="btn btn-primary" href="/notes/">
            <i class="fa-solid fa-pen-nib me-1"></i> WordPro
          </a>
        </li>

        <!-- User -->
        <?php if (currentUser()): ?>
        <li class="nav-item dropdown">
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
        <li class="nav-item">
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
