<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';
$user = require_admin();

$period = (string)get('period', 'today');
if (!in_array($period, ['today', 'week', 'month'], true)) {
    $period = 'today';
}
$from = match ($period) {
    'week'  => date('Y-m-d 00:00:00', strtotime('monday this week')),
    'month' => date('Y-m-01 00:00:00'),
    default => date('Y-m-d 00:00:00'),
};
$todayStart = date('Y-m-d 00:00:00');
$tomorrowStart = date('Y-m-d 00:00:00', strtotime('tomorrow'));

// Per-user stats for the period, plus callbacks due today.
$rows = q_all(
    "SELECT u.id, u.name, u.role, u.active,
        COALESCE(s.dials, 0) AS dials,
        COALESCE(s.connects, 0) AS connects,
        COALESCE(s.demos, 0) AS demos,
        (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.id AND l.callback_at >= ? AND l.callback_at < ?) AS cb_today
     FROM users u
     LEFT JOIN (
        SELECT user_id,
            COUNT(*) AS dials,
            SUM(outcome NOT IN ('No-Answer','Invalid')) AS connects,
            SUM(outcome = 'Demo Booked') AS demos
        FROM call_logs WHERE created_at >= ? GROUP BY user_id
     ) s ON s.user_id = u.id
     WHERE u.active = 1 OR COALESCE(s.dials, 0) > 0
     ORDER BY dials DESC, u.name",
    [$todayStart, $tomorrowStart, $from]
);
$tot = ['dials' => 0, 'connects' => 0, 'demos' => 0, 'cb_today' => 0];
foreach ($rows as $r) {
    foreach ($tot as $k => $v) {
        $tot[$k] += (int)$r[$k];
    }
}
$pct = static fn(int $n, int $d): string => $d > 0 ? number_format($n / $d * 100, 1) . '%' : '—';

// Dials per day, last 14 days.
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $days[date('Y-m-d', strtotime("-$i days"))] = 0;
}
$since = array_key_first($days) . ' 00:00:00';
foreach (q_all('SELECT DATE(created_at) AS d, COUNT(*) AS n FROM call_logs WHERE created_at >= ? GROUP BY DATE(created_at)', [$since]) as $r) {
    if (isset($days[$r['d']])) {
        $days[$r['d']] = (int)$r['n'];
    }
}
$maxDay = max(1, max($days));

page_header('Dashboard');
?>
<div class="toolbar">
  <h1>Dashboard</h1>
  <div class="segmented">
    <a href="dashboard.php?period=today" class="<?= $period === 'today' ? 'active' : '' ?>">Today</a>
    <a href="dashboard.php?period=week" class="<?= $period === 'week' ? 'active' : '' ?>">This week</a>
    <a href="dashboard.php?period=month" class="<?= $period === 'month' ? 'active' : '' ?>">This month</a>
  </div>
</div>

<div class="stats">
  <div class="stat"><div class="v"><?= number_format($tot['dials']) ?></div><div class="l">Dials</div></div>
  <div class="stat"><div class="v"><?= number_format($tot['connects']) ?></div><div class="l">Connects</div></div>
  <div class="stat"><div class="v"><?= $pct($tot['connects'], $tot['dials']) ?></div><div class="l">Connect rate</div></div>
  <div class="stat"><div class="v"><?= number_format($tot['demos']) ?></div><div class="l">Demos booked</div></div>
  <div class="stat"><div class="v"><?= $pct($tot['demos'], $tot['dials']) ?></div><div class="l">Booking rate / dial</div></div>
  <div class="stat"><div class="v"><?= number_format($tot['cb_today']) ?></div><div class="l">Callbacks due today</div></div>
</div>

<div class="grid-2">
  <div class="table-wrap">
    <table class="table-tight">
      <thead><tr><th>Setter</th><th class="num">Dials</th><th class="num">Connects</th><th class="num">Connect %</th><th class="num">Demos</th><th class="num">Book %</th><th class="num">CB today</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="muted">No users yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['name']) ?><?= !(int)$r['active'] ? ' <small class="muted">inactive</small>' : '' ?></td>
          <td class="num"><?= (int)$r['dials'] ?></td>
          <td class="num"><?= (int)$r['connects'] ?></td>
          <td class="num"><?= $pct((int)$r['connects'], (int)$r['dials']) ?></td>
          <td class="num"><?= (int)$r['demos'] ?></td>
          <td class="num"><?= $pct((int)$r['demos'], (int)$r['dials']) ?></td>
          <td class="num"><?= (int)$r['cb_today'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th>Total</th><th class="num"><?= $tot['dials'] ?></th><th class="num"><?= $tot['connects'] ?></th><th class="num"><?= $pct($tot['connects'], $tot['dials']) ?></th><th class="num"><?= $tot['demos'] ?></th><th class="num"><?= $pct($tot['demos'], $tot['dials']) ?></th><th class="num"><?= $tot['cb_today'] ?></th></tr>
      </tfoot>
    </table>
  </div>
  <div class="card">
    <h3>Dials per day · last 14 days</h3>
    <?php if (array_sum($days) === 0): ?>
      <p class="muted">No dials logged in the last 14 days.</p>
    <?php else: ?>
    <div class="bars">
      <?php foreach ($days as $d => $n): ?>
        <div class="bar" title="<?= e(date('D j M', strtotime($d))) ?>: <?= $n ?> dials">
          <span class="n"><?= $n > 0 ? $n : '' ?></span>
          <div class="fill" style="height:<?= (int)round($n / $maxDay * 100) ?>%"></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="bar-labels">
      <?php foreach ($days as $d => $n): ?><span><?= e(date('j', strtotime($d))) ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
