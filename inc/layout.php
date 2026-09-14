<?php
declare(strict_types=1);

function page_header(string $title, array $opts = []): void
{
    $app = config('app_name', 'Nexus Edge CRM');
    $user = current_user();
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $bodyClass = $opts['body_class'] ?? '';
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($app) ?></title>
<link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body class="<?= e($bodyClass) ?>">
<?php if ($user): ?>
<header class="topbar">
  <a class="brand" href="dial.php"><?= e($app) ?></a>
  <button class="navtoggle" type="button" aria-label="Menu" onclick="document.body.classList.toggle('nav-open')">☰</button>
  <nav class="nav">
    <?php
    $items = [
        'dial.php'      => 'Dial',
        'callbacks.php' => 'Callbacks',
        'leads.php'     => 'Leads',
    ];
    if ($user['role'] === 'admin') {
        $items['import.php']    = 'Import';
        $items['users.php']     = 'Users';
        $items['dashboard.php'] = 'Dashboard';
    }
    foreach ($items as $href => $label):
        $active = ($current === $href) || ($href === 'leads.php' && $current === 'lead.php');
    ?>
      <a href="<?= e($href) ?>" class="<?= $active ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="userbox">
    <span class="uname"><?= e($user['name']) ?> <small><?= e($user['role']) ?></small></span>
    <form method="post" action="logout.php" class="inline"><?= csrf_field() ?><button class="btn btn-sm btn-ghost" type="submit">Log out</button></form>
  </div>
</header>
<?php endif; ?>
<main class="main">
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
<?php
}

function page_footer(): void
{
    ?>
</main>
<script src="assets/app.js?v=1"></script>
</body>
</html>
<?php
}
