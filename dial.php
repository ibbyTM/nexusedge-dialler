<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';
$user = require_login();
$uid = (int)$user['id'];

$errors = [];
$lead = null;
$posted = ['outcome' => '', 'note' => '', 'callback_at' => ''];

// ---- Save outcome and load next ----
if (is_post()) {
    csrf_verify();
    $leadId = (int)post('lead_id', 0);
    $posted = [
        'outcome'     => post_str('outcome', 30),
        'note'        => mb_substr(trim((string)post('note')), 0, 5000),
        'callback_at' => post_str('callback_at', 30),
    ];
    $lead = $leadId > 0 ? find_lead_for_user($user, $leadId) : null;
    if (!$lead) {
        flash('err', 'That lead is no longer available to you. Loaded the next one.');
        redirect('dial.php');
    }
    if (!in_array($posted['outcome'], OUTCOMES, true)) {
        $errors[] = 'Pick an outcome.';
    }
    $needsCallback = in_array($posted['outcome'], OUTCOMES_NEED_CALLBACK, true);
    $callbackAt = null;
    if ($needsCallback) {
        $callbackAt = parse_datetime_local($posted['callback_at']);
        if ($callbackAt === null) {
            $errors[] = 'Set a callback date and time for ' . $posted['outcome'] . '.';
        }
    }
    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ts = now();
            q('INSERT INTO call_logs (lead_id, user_id, outcome, note, callback_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$leadId, $uid, $posted['outcome'], $posted['note'] !== '' ? $posted['note'] : null, $callbackAt, $ts]);
            $params = [$posted['outcome'], $ts, $callbackAt];
            $noteSql = '';
            if ($posted['note'] !== '') {
                $noteSql = ', notes = ?';
                $params[] = $posted['note'];
            }
            $params[] = $ts;
            $params[] = $leadId;
            q("UPDATE leads SET dial_status = ?, last_dial_at = ?, callback_at = ?, dial_attempts = dial_attempts + 1 $noteSql, updated_at = ? WHERE id = ?", $params);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log('Nexus Edge CRM dial save failed: ' . $ex->getMessage());
            $errors[] = 'Could not save the outcome. Try again.';
        }
        if (!$errors) {
            flash('ok', 'Saved ' . $posted['outcome'] . ' for ' . ($lead['company_name'] ?: 'lead #' . $leadId) . '.');
            redirect('dial.php');
        }
    }
}

// ---- Which lead to show ----
$explicit = (int)get('lead', 0);
if (!$lead) {
    if ($explicit > 0) {
        $lead = find_lead_for_user($user, $explicit);
        if (!$lead) {
            flash('err', 'That lead is not available to you.');
            redirect('dial.php');
        }
    } else {
        $lead = queue_next_lead($uid);
    }
}
$queueCount = queue_count($uid);

page_header('Dial', ['body_class' => 'dial']);

if (!$lead) {
    ?>
<div class="empty">
  <h2>No leads in your queue</h2>
  <p>Nothing is due right now. New leads, due callbacks and No-Answer retries (after 24 hours) will appear here automatically.</p>
  <p><a class="btn" href="callbacks.php">See upcoming callbacks</a> <a class="btn btn-ghost" href="dial.php">Refresh</a></p>
</div>
<?php
    page_footer();
    exit;
}

$logs = q_all(
    'SELECT c.outcome, c.note, c.callback_at, c.created_at, u.name AS user_name
     FROM call_logs c LEFT JOIN users u ON u.id = c.user_id
     WHERE c.lead_id = ? ORDER BY c.created_at DESC, c.id DESC LIMIT 3',
    [(int)$lead['id']]
);
$telHref = $lead['phone_e164'] ?: preg_replace('/[^\d+]/', '', (string)$lead['phone_raw']);
$phoneText = $lead['phone_e164'] ? format_phone_display($lead['phone_e164']) : ($lead['phone_raw'] ?: 'No phone');
$web = trim((string)$lead['website']);
$webHref = $web !== '' && !preg_match('~^https?://~i', $web) ? 'http://' . $web : $web;
?>
<?php foreach ($errors as $err): ?><div class="flash flash-err"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($lead['do_not_dial']): ?><div class="flash flash-err">This lead is marked do not dial.</div><?php endif; ?>
<div class="dial-card">
  <div class="dial-head">
    <div>
      <h1 class="dial-company"><?= e($lead['company_name'] ?: '(no company)') ?></h1>
      <p class="dial-contact"><?= e($lead['contact_name'] ?: 'No contact name') ?><?= $lead['job_title'] ? ' <small>· ' . e($lead['job_title']) . '</small>' : '' ?></p>
    </div>
    <div class="dial-meta">
      <span class="pill <?= status_class($lead['dial_status']) ?>"><?= e($lead['dial_status']) ?></span>
      <span>Tier <span class="tier"><?= e($lead['tier']) ?></span></span>
      <span><?= (int)$lead['dial_attempts'] ?> dial<?= (int)$lead['dial_attempts'] === 1 ? '' : 's' ?></span>
      <?php if ($lead['callback_at']): ?><span>Callback <?= e(fmt_dt($lead['callback_at'], 'D j M H:i')) ?></span><?php endif; ?>
      <span class="muted"><?= $queueCount ?> in queue</span>
    </div>
  </div>

  <?php if ($telHref): ?>
    <a class="dial-phone <?= $lead['phone_e164'] ? '' : 'invalid' ?>" href="tel:<?= e($telHref) ?>"><?= e($phoneText) ?></a>
  <?php else: ?>
    <div class="dial-phone invalid">No phone number</div>
  <?php endif; ?>

  <div class="dial-links">
    <?php if ($lead['email']): ?><a href="mailto:<?= e($lead['email']) ?>"><?= e($lead['email']) ?></a><?php endif; ?>
    <?php if ($web !== ''): ?><a href="<?= e($webHref) ?>" target="_blank" rel="noopener"><?= e(preg_replace('~^https?://(www\.)?~i', '', $web)) ?></a><?php endif; ?>
    <?php if ($lead['town']): ?><span class="muted"><?= e($lead['town']) ?><?= $lead['postcode'] ? ' ' . e($lead['postcode']) : '' ?></span><?php endif; ?>
    <a href="lead.php?id=<?= (int)$lead['id'] ?>" class="muted">Full record</a>
  </div>

  <?php if ($lead['notes']): ?>
    <div class="dial-notes-prev"><?= e($lead['notes']) ?></div>
  <?php endif; ?>

  <?php if ($logs): ?>
    <h3>Last calls</h3>
    <ul class="history">
      <?php foreach ($logs as $c): ?>
        <li>
          <span class="when"><?= e(fmt_dt($c['created_at'], 'j M H:i')) ?></span>
          <span><span class="pill <?= status_class($c['outcome']) ?>"><?= e($c['outcome']) ?></span> <small class="muted"><?= e($c['user_name'] ?? '') ?></small></span>
          <span class="note"><?= e($c['note']) ?><?= $c['callback_at'] ? ' <small class="muted">· cb ' . e(fmt_dt($c['callback_at'], 'j M H:i')) . '</small>' : '' ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <form method="post" id="dial-form" data-need-callback="<?= e(implode('|', OUTCOMES_NEED_CALLBACK)) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
    <input type="hidden" name="outcome" value="<?= e($posted['outcome']) ?>">
    <div class="outcomes">
      <?php foreach (OUTCOMES as $i => $o): ?>
        <button type="button" class="outcome <?= $posted['outcome'] === $o ? 'selected' : '' ?>" data-outcome="<?= e($o) ?>"><span class="key"><?= $i + 1 ?></span><?= e($o) ?></button>
      <?php endforeach; ?>
    </div>
    <label class="field">Notes<textarea name="note" id="note" placeholder="What happened on this call?"><?= e($posted['note']) ?></textarea></label>
    <label class="field callback-field <?= in_array($posted['outcome'], OUTCOMES_NEED_CALLBACK, true) ? '' : 'hidden' ?>" id="callback-field">Callback date and time <span class="req">*</span><input type="datetime-local" name="callback_at" id="callback_at" value="<?= e($posted['callback_at']) ?>"></label>
    <div class="dial-save">
      <button class="btn btn-primary" type="submit">Save and next</button>
      <span class="shortcut-hint">Keys 1–8 pick an outcome · Enter saves · Shift+Enter for a new line in notes · Esc leaves a field</span>
    </div>
  </form>
</div>
<?php page_footer();
