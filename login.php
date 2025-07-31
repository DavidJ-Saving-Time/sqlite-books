<?php
require_once 'db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if (validateUser($username, $password)) {
        setcookie('user', $username, time() + 60 * 60 * 24 * 30);
        header('Location: list_books.php');
        exit;
    }
    $error = 'Invalid credentials';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <link rel="manifest" href="manifest.json">
  <title>Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    html, body {
      height: 100%;
      margin: 0;
      padding: 0;
    }
    body {
      background: url('libwall.jpg') no-repeat center center fixed;
      background-size: cover;
      display: flex;
      justify-content: center;
      align-items: center;
    }
    .login-container {
      background: rgba(255, 255, 255, 0.85);
      padding: 2rem;
      border-radius: 8px;
      box-shadow: 0 0 10px rgba(0, 0, 0, 0.3);
      width: 100%;
      max-width: 400px;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <h1 class="mb-4 text-center">Login</h1>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="post">
      <div class="mb-3">
        <label for="username" class="form-label">Username</label>
        <input type="text" id="username" name="username" class="form-control">
      </div>
      <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" id="password" name="password" class="form-control">
      </div>
  <button type="submit" class="btn btn-primary w-100">Login</button>
    </form>
  </div>
  <script src="js/pwa.js"></script>
</body>
</html>

