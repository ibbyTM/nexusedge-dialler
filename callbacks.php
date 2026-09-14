<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';
$user = require_login();
$admin = $user['role'] === 'admin';

$params = [];
$scope = lead_scope_sql($user, $params);
$rows = q_all(
    LEAD_SELECT . " WHERE $scope AND l.callback_at IS NOT NULL ORDER BY l.callback_at ASC, l.id ASC LIMIT 1000",
    $params
);

$nowTs = time();
$tomorrow = strtotime('tomorrow');
$groups = ['overdue' => [], 'today' => [], 'upcoming' => []];
foreach ($rows as $r) {
    $ts = strtotime((string)$r['callback_at']) ?: 0;
    if ($ts < $nowTs) {
        $groups['overdue'][] = $r;
    } elseif ($ts < $tomorrow) {
        $groups['today'][] = $r;
    } else {
        $groups['upcoming'][] = $r;
    }
}
$titles = ['overdue' => 'Overdue', 'today' => 'Today', 'upcoming' => 'Upcoming'];

page_header('Callbacks');
?>
<div class="toolbar">
  <h1>Callbacks</h1>
  <span class="muted"><?= count($rows) ?> scheduled</span>
</div>
<?php if (!$rows): ?>
  <div class="empty">
    <h2>No callbacks scheduled</h2>
    <p>Leads you mark as Setter Callback, Follow-Up or Reschedule will show here.</p>
    <p><a class="btn btn-primary" href="dial.php">Go to dial queue</a></p>
  </div>
<?php endif; ?>
<?php foreach ($groups as $key => $list): if (!$list) { continue; } ?>
<div class="section">
  <h2 class="<?= $key === 'overdue' ? 'overdue' : '' ?>"><?= e($titles[$key]) ?> <span class="count"><?= count($list) ?></span></h2>
  <div class="table-wrap">
    <table class="table-tight table-mobile-stack">
      <thead>
        <tr><th>When</th><th>Company</th><th>Contact</th><th>Phone</th><th>Status</th><?php if ($admin): ?><th>Setter</th><?php endif; ?><th>Note</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($list as $r): ?>
        <tr>
          <td data-label="When"><strong><?= e(fmt_dt($r['callback_at'], $key === 'upcoming' ? 'D j M H:i' : 'H:i')) ?></strong><?= $key === 'overdue' ? ' <small class="muted">' . e(fmt_dt($r['callback_at'], 'j M')) . '</small>' : '' ?></td>
          <td data-label="Company"><a href="lead.php?id=<?= (int)$r['id'] ?>"><?= e($r['company_name'] ?: '(no company)') ?></a></td>
          <td data-label="Contact"><?= e($r['contact_name']) ?></td>
          <td data-label="Phone"><?= $r['phone_e164'] ? '<a href="tel:' . e($r['phone_e164']) . '">' . e(format_phone_display($r['phone_e164'])) . '</a>' : e($r['phone_raw']) ?></td>
          <td data-label="Status"><span class="pill <?= status_class($r['dial_status']) ?>"><?= e($r['dial_status']) ?></span><?= $r['do_not_dial'] ? ' <span class="pill st-dq">DND</span>' : '' ?></td>
          <?php if ($admin): ?><td data-label="Setter"><?= e($r['assigned_name'] ?? '—') ?></td><?php endif; ?>
          <td class="wrap" data-label="Note"><?= e(mb_substr((string)$r['notes'], 0, 120)) ?></td>
          <td><?php if (!$r['do_not_dial']): ?><a class="btn btn-sm btn-primary" href="dial.php?lead=<?= (int)$r['id'] ?>">Dial now</a><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>
<?php page_footer();
