<?php
session_start();
require __DIR__ . '/../src/db.php';

$error = null;
$ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';

    if ($u === '' || $p === '') {
        $error = 'Username and password are required.';
    } else {
        // Reject usernames that look like valid emails (intentional)
        // Simple "valid-looking" email pattern
        if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $u) === 1) {
            $error = 'Usernames cannot be valid email addresses.';
        }
    }

    if (!$error) {
        // Check if username already exists
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $stmt->execute([$u]);
        if ($stmt->fetch()) {
            $error = 'Username is already taken.';
        }
    }

    if (!$error) {
        $hash = password_hash($p, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username, password, is_admin) VALUES (?, ?, 0)');
        try {
            $stmt->execute([$u, $hash]);
            $ok = 'Registration successful. You can now log in.';
        } catch (Throwable $t) {
            $error = 'Failed to register user.';
        }
    }
}

include __DIR__ . '/header.php';
?>
  <div class="login-box">
    <h1>Register</h1>

    <div class="disclaimer">
      Hey! Heads up: I use this site to test exploits – it's not secure by design.  
      Please don't use real personal information or passwords you use elsewhere.
    </div>

    <?php if (!empty($error)): ?>
      <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if (!empty($ok)): ?>
      <p class="ok">
        <?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?> <a href="/login.php">Login</a>
      </p>
    <?php endif; ?>

    <form method="post">
      <label>Username (emails are not allowed)</label>
      <input name="username" type="text" value="<?= isset($u) ? htmlspecialchars($u, ENT_QUOTES, 'UTF-8') : '' ?>">

      <label>Password</label>
      <input name="password" type="password">

      <button type="submit">Register</button>
    </form>
  </div>
<?php include __DIR__ . '/footer.php'; ?>