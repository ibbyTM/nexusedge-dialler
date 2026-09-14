<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';
$user = require_login();
$admin = $user['role'] === 'admin';

// ---- Bulk actions (admin only) ----
if (is_post()) {
    csrf_verify();
    if (!$admin) {
        http_response_code(403);
        exit('Not allowed');
    }
    $ids = array_values(array_unique(array_map('intval', (array)post('ids', []))));
    $ids = array_filter($ids, static fn($v) => $v > 0);
    $action = (string)post('bulk_action');
    $back = (string)post('return', 'leads.php');
    if ($back === '' || $back[0] === '/' || str_contains($back, '://') || !str_starts_with($back, 'leads.php')) {
        $back = 'leads.php';
    }
    if (!$ids) {
        flash('err', 'No leads selected.');
        redirect($back);
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $n = 0;
    switch ($action) {
        case 'assign':
            $to = (int)post('assign_to');
            $target = q_one('SELECT id FROM users WHERE id = ? AND active = 1', [$to]);
            if (!$target) {
                flash('err', 'Choose a setter to assign to.');
                redirect($back);
            }
            $n = q("UPDATE leads SET assigned_to = ?, updated_at = ? WHERE id IN ($ph)", array_merge([$to, now()], $ids))->rowCount();
            flash('ok', "Assigned $n lead(s).");
            break;
        case 'unassign':
            $n = q("UPDATE leads SET assigned_to = NULL, updated_at = ? WHERE id IN ($ph)", array_merge([now()], $ids))->rowCount();
            flash('ok', "Unassigned $n lead(s).");
            break;
        case 'dnd_on':
            $n = q("UPDATE leads SET do_not_dial = 1, updated_at = ? WHERE id IN ($ph)", array_merge([now()], $ids))->rowCount();
            flash('ok', "Marked $n lead(s) as do not dial.");
            break;
        case 'dnd_off':
            $n = q("UPDATE leads SET do_not_dial = 0, updated_at = ? WHERE id IN ($ph)", array_merge([now()], $ids))->rowCount();
            flash('ok', "Cleared do not dial on $n lead(s).");
            break;
        case 'delete':
            $n = q("DELETE FROM leads WHERE id IN ($ph)", $ids)->rowCount();
            flash('ok', "Deleted $n lead(s).");
            break;
        default:
            flash('err', 'Choose a bulk action.');
    }
    redirect($back);
}

// ---- List ----
[$whereSql, $params, $f] = leads_filter($user, $_GET);
$perPage = 50;
$page = max(1, (int)get('page', 1));
$total = (int)q_val("SELECT COUNT(*) FROM leads l WHERE $whereSql", $params);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$rows = q_all(
    LEAD_SELECT . " WHERE $whereSql ORDER BY l.id DESC LIMIT $perPage OFFSET $offset",
    $params
);

$users = $admin ? assignable_users() : [];
$batches = q_all("SELECT DISTINCT batch_name FROM leads WHERE batch_name <> '' ORDER BY batch_name");
$returnUrl = url_with([]);

page_header('Leads');
?>
<div class="toolbar">
  <h1>Leads <small class="muted"><?= number_format($total) ?></small></h1>
  <div class="actions">
    <?php if ($admin): ?>
      <a class="btn btn-sm" href="import.php">Import CSV</a>
      <a class="btn btn-sm" href="<?= e(url_with([], 'export.php')) ?>">Export CSV</a>
    <?php endif; ?>
  </div>
</div>

<form method="get" class="filters">
  <input type="search" name="q" placeholder="Search company, contact, phone, email" value="<?= e($f['q']) ?>" class="span2">
  <select name="status">
    <option value="">Any status</option>
    <?php foreach (DIAL_STATUSES as $s): ?>
      <option value="<?= e($s) ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($admin): ?>
  <select name="assigned">
    <option value="">Any setter</option>
    <option value="none" <?= $f['assigned'] === 'none' ? 'selected' : '' ?>>Unassigned</option>
    <?php foreach ($users as $u): ?>
      <option value="<?= (int)$u['id'] ?>" <?= $f['assigned'] === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select name="tier">
    <option value="">Any tier</option>
    <?php foreach (TIERS as $t): ?>
      <option value="<?= $t ?>" <?= $f['tier'] === $t ? 'selected' : '' ?>>Tier <?= $t ?></option>
    <?php endforeach; ?>
  </select>
  <select name="batch">
    <option value="">Any batch</option>
    <?php foreach ($batches as $b): ?>
      <option value="<?= e($b['batch_name']) ?>" <?= $f['batch'] === $b['batch_name'] ? 'selected' : '' ?>><?= e($b['batch_name']) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="town" placeholder="Town" value="<?= e($f['town']) ?>">
  <select name="dnd">
    <option value="">Do not dial: any</option>
    <option value="0" <?= $f['dnd'] === '0' ? 'selected' : '' ?>>Dialable only</option>
    <option value="1" <?= $f['dnd'] === '1' ? 'selected' : '' ?>>Do not dial only</option>
  </select>
  <div class="actions">
    <button class="btn btn-sm" type="submit">Filter</button>
    <a class="btn btn-sm btn-ghost" href="leads.php">Clear</a>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="empty">
    <h2>No leads found</h2>
    <p><?= $total === 0 && !array_filter($f) ? ($admin ? 'Import a CSV to get started.' : 'Nothing has been assigned to you yet.') : 'Try clearing some filters.' ?></p>
  </div>
<?php else: ?>
<form method="post" id="bulk-form">
  <?= csrf_field() ?>
  <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
  <?php if ($admin): ?>
  <div class="bulkbar">
    <span id="bulk-count" class="muted">0 selected</span>
    <select name="bulk_action" required>
      <option value="">Bulk action…</option>
      <option value="assign">Assign to setter</option>
      <option value="unassign">Unassign</option>
      <option value="dnd_on">Set do not dial</option>
      <option value="dnd_off">Clear do not dial</option>
      <option value="delete">Delete</option>
    </select>
    <select name="assign_to">
      <option value="">Setter…</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit">Apply</button>
  </div>
  <?php endif; ?>
  <div class="table-wrap">
    <table class="table-tight">
      <thead>
        <tr>
          <?php if ($admin): ?><th><input type="checkbox" id="select-all" aria-label="Select all"></th><?php endif; ?>
          <th>Company</th>
          <th>Contact</th>
          <th>Phone</th>
          <th>Town</th>
          <th>Tier</th>
          <th>Status</th>
          <?php if ($admin): ?><th>Setter</th><?php endif; ?>
          <th class="num">Dials</th>
          <th>Last dial</th>
          <th>Callback</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr class="row-link" data-href="lead.php?id=<?= (int)$r['id'] ?>">
          <?php if ($admin): ?><td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"></td><?php endif; ?>
          <td><a href="lead.php?id=<?= (int)$r['id'] ?>"><?= e($r['company_name'] ?: '(no company)') ?></a><?= $r['do_not_dial'] ? ' <span class="pill st-dq">DND</span>' : '' ?></td>
          <td><?= e($r['contact_name']) ?></td>
          <td><?= e($r['phone_e164'] ? format_phone_display($r['phone_e164']) : $r['phone_raw']) ?></td>
          <td><?= e($r['town']) ?></td>
          <td class="tier"><?= e($r['tier']) ?></td>
          <td><span class="pill <?= status_class($r['dial_status']) ?>"><?= e($r['dial_status']) ?></span></td>
          <?php if ($admin): ?><td><?= e($r['assigned_name'] ?? '—') ?></td><?php endif; ?>
          <td class="num"><?= (int)$r['dial_attempts'] ?></td>
          <td><?= e(fmt_dt($r['last_dial_at'], 'j M H:i')) ?></td>
          <td><?= e(fmt_dt($r['callback_at'], 'j M H:i')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</form>
<div class="pager">
  <span class="muted">Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> leads</span>
  <span class="actions">
    <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url_with(['page' => $page - 1])) ?>">← Previous</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e(url_with(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
  </span>
</div>
<?php endif; ?>
<?php page_footer();
