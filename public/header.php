<?php
// shared header include
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>senn roulette</title>
  <link rel="icon" type="image/png" href="/img/icons/senn_icon.png">
  <link rel="stylesheet" href="/css/global.css">
</head>
<body>
<header>
  <a class="btn" href="/index.php">Home</a>
  <a class="btn" href="/preferences.php">Preferences</a>
  <?php if (!empty($_SESSION['uid'])): ?>
    <?php if (!empty($_SESSION['is_admin'])): ?>
      <a class="btn" href="/admin/admin.php">Admin</a>
    <?php endif; ?>
    <a class="btn" href="/logout.php">Logout</a>
  <?php else: ?>
    <a class="btn" href="/login.php">Login/Register</a>
  <?php endif; ?>
</header>
<main>

