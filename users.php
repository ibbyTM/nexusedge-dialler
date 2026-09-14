<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';
$user = require_admin();
$me = (int)$user['id'];
$errors = [];
$form = ['name' => '', 'email' => '', 'role' => 'setter'];

if (is_post()) {
    csrf_verify();
    $action = (string)post('action', '');
    $targetId = (int)post('user_id', 0);
    $target = $targetId > 0 ? q_one('SELECT * FROM users WHERE id = ?', [$targetId]) : null;

    if ($action === 'create') {
        $form = ['name' => post_str('name', 100), 'email' => mb_strtolower(post_str('email', 190)), 'role' => post_str('role', 10)];
        $pass = (string)post('password');
        if ($form['name'] === '') {
            $errors[] = 'Name is required.';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        } elseif (q_one('SELECT id FROM users WHERE email = ?', [$form['email']])) {
            $errors[] = 'That email is already in use.';
        }
        if (!in_array($form['role'], ['admin', 'setter'], true)) {
            $errors[] = 'Choose a role.';
        }
        if (strlen($pass) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }
        if (!$errors) {
            q('INSERT INTO users (name, email, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)',
                [$form['name'], $form['email'], password_hash($pass, PASSWORD_BCRYPT), $form['role'], now()]);
            flash('ok', 'User ' . $form['name'] . ' created.');
            redirect('users.php');
        }
    } elseif (!$target) {
        flash('err', 'User not found.');
        redirect('users.php');
    } elseif ($action === 'toggle_active') {
        if ($targetId === $me) {
            flash('err', 'You cannot deactivate your own account.');
        } else {
            $new = (int)$target['active'] ? 0 : 1;
            q('UPDATE users SET active = ? WHERE id = ?', [$new, $targetId]);
            flash('ok', $target['name'] . ($new ? ' reactivated.' : ' deactivated. Their leads stay assigned; reassign them from the Leads page.'));
        }
        redirect('users.php');
    } elseif ($action === 'role') {
        $role = post_str('role', 10);
        if ($targetId === $me) {
            flash('err', 'You cannot change your own role.');
        } elseif (!in_array($role, ['admin', 'setter'], true)) {
            flash('err', 'Choose a role.');
        } else {
            q('UPDATE users SET role = ? WHERE id = ?', [$role, $targetId]);
            flash('ok', $target['name'] . ' is now ' . $role . '.');
        }
        redirect('users.php');
    } elseif ($action === 'password') {
        $pass = (string)post('password');
        if (strlen($pass) < 10) {
            flash('err', 'Password must be at least 10 characters.');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_BCRYPT), $targetId]);
            flash('ok', 'Password reset for ' . $target['name'] . '.');
        }
        redirect('users.php');
    } else {
        redirect('users.php');
    }
}

$todayStart = date('Y-m-d 00:00:00');
$users = q_all(
    'SELECT u.*,
        (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.id) AS lead_count,
        (SELECT COUNT(*) FROM call_logs c WHERE c.user_id = u.id AND c.created_at >= ?) AS dials_today
     FROM users u ORDER BY u.active DESC, u.role = \'admin\', u.name',
    [$todayStart]
);

page_header('Users');
?>
<div class="toolbar"><h1>Users</h1></div>
<?php foreach ($errors as $err): ?><div class="flash flash-err"><?= e($err) ?></div><?php endforeach; ?>
<div class="grid-2">
  <div class="table-wrap" style="grid-column: 1 / -1">
    <table class="table-tight">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th class="num">Leads</th><th class="num">Dials today</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): $self = (int)$u['id'] === $me; ?>
        <tr>
          <td><strong><?= e($u['name']) ?></strong><?= $self ? ' <small class="muted">(you)</small>' : '' ?></td>
          <td><?= e($u['email']) ?></td>
          <td>
            <?php if ($self): ?>
              <?= e($u['role']) ?>
            <?php else: ?>
              <form method="post" class="inline actions">
                <?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <select name="role" style="width:auto;display:inline-block;margin:0;min-height:32px;padding:.2rem .4rem">
                  <option value="setter" <?= $u['role'] === 'setter' ? 'selected' : '' ?>>setter</option>
                  <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                </select>
                <button class="btn btn-sm" type="submit">Set</button>
              </form>
            <?php endif; ?>
          </td>
          <td class="num"><a href="leads.php?assigned=<?= (int)$u['id'] ?>"><?= (int)$u['lead_count'] ?></a></td>
          <td class="num"><?= (int)$u['dials_today'] ?></td>
          <td><?= (int)$u['active'] ? '<span class="pill st-demo-booked">active</span>' : '<span class="pill st-dq">inactive</span>' ?></td>
          <td>
            <div class="actions">
              <?php if (!$self): ?>
              <form method="post" class="inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm <?= (int)$u['active'] ? 'btn-danger' : '' ?>" type="submit" <?= (int)$u['active'] ? 'data-confirm="Deactivate ' . e($u['name']) . '? They will be logged out and cannot log in."' : '' ?>><?= (int)$u['active'] ? 'Deactivate' : 'Reactivate' ?></button>
              </form>
              <?php endif; ?>
              <form method="post" class="inline actions">
                <?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <input type="password" name="password" placeholder="New password" minlength="10" required autocomplete="new-password" style="width:150px;display:inline-block;margin:0;min-height:32px;padding:.2rem .4rem">
                <button class="btn btn-sm" type="submit">Reset</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card">
    <h3>Add user</h3>
    <form method="post" class="form" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <label>Name<input type="text" name="name" required maxlength="100" value="<?= e($form['name']) ?>"></label>
      <label>Email<input type="email" name="email" required maxlength="190" value="<?= e($form['email']) ?>"></label>
      <div class="form-row">
        <label>Role<select name="role"><option value="setter" <?= $form['role'] === 'setter' ? 'selected' : '' ?>>Setter</option><option value="admin" <?= $form['role'] === 'admin' ? 'selected' : '' ?>>Admin</option></select></label>
        <label>Password (min 10)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
      </div>
      <button class="btn btn-primary" type="submit">Create user</button>
    </form>
  </div>
</div>
<?php page_footer();
