<?php

session_start();

require __DIR__ . '/../src/db.php';



$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $u = trim($_POST['username'] ?? '');

    $p = $_POST['password'] ?? '';



    $stmt = $pdo->prepare("SELECT id,username,password,is_admin FROM users WHERE username=?");

    $stmt->execute([$u]);

    $user = $stmt->fetch();



    if ($user && password_verify($p, $user['password'])) {

        $_SESSION['uid'] = $user['id'];

        $_SESSION['username'] = $user['username'];

        $_SESSION['is_admin'] = (int)$user['is_admin'];

        header("Location: /admin/admin.php");

        exit;

    } else {

        $error = "Invalid login.";

    }

}


include __DIR__ . '/header.php';

?>

  <div class="login-box">

    <h1>Login</h1>



    <div class="disclaimer">

      Hey! Heads up: I use this site to test exploits – it's not secure by design.  

      Please don't use real personal information or passwords you use elsewhere.

    </div>



    <?php if (!empty($error)): ?>

      <p class="error"><?= htmlspecialchars($error) ?></p>

    <?php endif; ?>



    <form method="post">

      <label>Username</label>

      <input name="username" type="text">

      <label>Password</label>

      <input name="password" type="password">

      <button type="submit">Login</button>

    </form>

    <!-- Register button inside the login page -->
    <p style="margin-top:1rem; display:inline-flex; align-items:center; gap:0.5rem;">
      No account?
      <button type="button" onclick="location.href='/register.php'">Register</button>
    </p>
  </div>

<?php include __DIR__ . '/footer.php'; ?>


