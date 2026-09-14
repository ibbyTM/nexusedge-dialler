<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';

if (current_user()) {
    redirect('dial.php');
}

/** Only allow a relative path on this site: starts with a single slash, no scheme, no backslashes. */
function safe_next(string $n): bool
{
    return $n !== '' && strlen($n) < 500 && preg_match('~^/(?![/\\\\])[A-Za-z0-9_\-./?=&%+]*$~', $n) === 1;
}

$error = '';
$email = '';
$next = (string)get('next', '');
// Only allow relative, same-app redirects.
if (!safe_next($next)) {
    $next = '';
}

if (is_post()) {
    csrf_verify();
    $email = post_str('email', 190);
    $password = (string)post('password');
    $postedNext = (string)post('next', '');
    if (safe_next($postedNext)) {
        $next = $postedNext;
    }
    if (login_rate_limited()) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } elseif ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
    } elseif (login_attempt($email, $password)) {
        redirect($next !== '' ? $next : 'dial.php');
    } else {
        login_record_failure();
        $error = login_rate_limited()
            ? 'Too many failed attempts. Try again in 15 minutes.'
            : 'Email or password is incorrect.';
    }
}

page_header('Log in', ['body_class' => 'auth']);
?>
<div class="card card-auth">
  <h1><?= e(config('app_name', 'Nexus Edge CRM')) ?></h1>
  <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form" autocomplete="on">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label>Email<input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn btn-primary btn-block" type="submit">Log in</button>
  </form>
</div>
<?php page_footer();
