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

  <?php if (!empty($_SESSION['uid'])): ?>

    <a class="btn" href="/logout.php">Logout</a>

  <?php else: ?>

    <a class="btn" href="/login.php">Login</a>

  <?php endif; ?>

</header>

<main>

