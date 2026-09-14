<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';
$user = require_login();
$admin = $user['role'] === 'admin';

$id = (int)get('id', 0);
$lead = $id > 0 ? find_lead_for_user($user, $id) : null;
if (!$lead) {
    http_response_code(404);
    page_header('Lead not found');
    echo '<div class="empty"><h2>Lead not found</h2><p>It may have been deleted or is not assigned to you.</p><p><a class="btn" href="leads.php">Back to leads</a></p></div>';
    page_footer();
    exit;
}

$errors = [];
if (is_post()) {
    csrf_verify();
    $action = (string)post('action', 'save');

    if ($action === 'delete') {
        if (!$admin) {
            http_response_code(403);
            exit('Not allowed');
        }
        q('DELETE FROM leads WHERE id = ?', [$id]);
        flash('ok', 'Lead deleted.');
        redirect('leads.php');
    }

    // ---- Save edit ----
    $fields = [
        'company_name' => post_str('company_name', 190),
        'contact_name' => post_str('contact_name', 190),
        'job_title'    => post_str('job_title', 190),
        'phone_raw'    => post_str('phone_raw', 64),
        'email'        => post_str('email', 190),
        'website'      => post_str('website', 255),
        'address'      => post_str('address', 255),
        'town'         => post_str('town', 120),
        'postcode'     => post_str('postcode', 20),
        'notes'        => mb_substr(trim((string)post('notes')), 0, 15000),
        'do_not_dial'  => post('do_not_dial') ? 1 : 0,
    ];
    $fields['phone_e164'] = normalise_phone($fields['phone_raw']);
    $warning = '';
    if ($fields['phone_raw'] !== '' && $fields['phone_e164'] === null) {
        $warning = 'The phone number is not a valid UK number. It was kept as typed but will not dedupe.';
    }
    if ($fields['phone_e164'] !== null) {
        $dupe = q_one('SELECT id FROM leads WHERE phone_e164 = ? AND id <> ?', [$fields['phone_e164'], $id]);
        if ($dupe) {
            $errors[] = 'Another lead (#' . (int)$dupe['id'] . ') already has this phone number.';
        }
    }
    if ($admin) {
        $tier = post_str('tier', 1);
        $fields['tier'] = in_array($tier, TIERS, true) ? $tier : $lead['tier'];
        $status = post_str('dial_status', 30);
        $fields['dial_status'] = in_array($status, DIAL_STATUSES, true) ? $status : $lead['dial_status'];
        $fields['source'] = post_str('source', 120);
        $fields['batch_name'] = post_str('batch_name', 190);
        $assign = post_str('assigned_to', 10);
        if ($assign === '') {
            $fields['assigned_to'] = null;
        } else {
            $ok = q_one('SELECT id FROM users WHERE id = ?', [(int)$assign]);
            $fields['assigned_to'] = $ok ? (int)$assign : $lead['assigned_to'];
        }
        $fields['callback_at'] = parse_datetime_local(post_str('callback_at', 30));
    }
    if (!$errors) {
        $set = [];
        $params = [];
        foreach ($fields as $k => $v) {
            $set[] = "$k = ?";
            $params[] = $v;
        }
        $set[] = 'updated_at = ?';
        $params[] = now();
        $params[] = $id;
        q('UPDATE leads SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
        flash($warning ? 'info' : 'ok', $warning ? 'Saved. ' . $warning : 'Lead saved.');
        redirect('lead.php?id=' . $id);
    }
    // Re-show the form with what was posted.
    $lead = array_merge($lead, $fields);
}

$logs = q_all(
    'SELECT c.*, u.name AS user_name FROM call_logs c LEFT JOIN users u ON u.id = c.user_id WHERE c.lead_id = ? ORDER BY c.created_at DESC, c.id DESC',
    [$id]
);
$custom = [];
if (!empty($lead['custom_json'])) {
    $decoded = json_decode((string)$lead['custom_json'], true);
    if (is_array($decoded)) {
        $custom = $decoded;
    }
}
$users = $admin ? assignable_users() : [];

page_header($lead['company_name'] ?: 'Lead');
?>
<div class="toolbar">
  <div>
    <h1><?= e($lead['company_name'] ?: '(no company)') ?> <span class="pill <?= status_class($lead['dial_status']) ?>"><?= e($lead['dial_status']) ?></span><?= $lead['do_not_dial'] ? ' <span class="pill st-dq">Do not dial</span>' : '' ?></h1>
    <div class="muted">
      Tier <?= e($lead['tier']) ?> · <?= (int)$lead['dial_attempts'] ?> dial(s)
      <?= $lead['last_dial_at'] ? '· last ' . e(fmt_dt($lead['last_dial_at'])) : '' ?>
      <?= $lead['callback_at'] ? '· callback ' . e(fmt_dt($lead['callback_at'])) : '' ?>
      <?= $lead['assigned_name'] ? '· ' . e($lead['assigned_name']) : '· unassigned' ?>
      <?= $lead['batch_name'] ? '· ' . e($lead['batch_name']) : '' ?>
    </div>
  </div>
  <div class="actions">
    <a class="btn" href="leads.php">← Leads</a>
    <?php if (!$lead['do_not_dial'] && ($admin || (int)$lead['assigned_to'] === (int)$user['id'])): ?>
      <a class="btn btn-primary" href="dial.php?lead=<?= (int)$lead['id'] ?>">Dial this lead</a>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($errors as $err): ?><div class="flash flash-err"><?= e($err) ?></div><?php endforeach; ?>

<div class="grid-2">
  <div class="stack">
    <div class="card">
      <h3>Edit lead</h3>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div class="form-row">
          <label>Company<input type="text" name="company_name" value="<?= e($lead['company_name']) ?>" maxlength="190"></label>
          <label>Contact<input type="text" name="contact_name" value="<?= e($lead['contact_name']) ?>" maxlength="190"></label>
        </div>
        <div class="form-row">
          <label>Job title<input type="text" name="job_title" value="<?= e($lead['job_title']) ?>" maxlength="190"></label>
          <label>Phone<input type="text" name="phone_raw" value="<?= e($lead['phone_raw']) ?>" maxlength="64" inputmode="tel"><small>Stored as: <?= e($lead['phone_e164'] ?? 'not a valid UK number') ?></small></label>
        </div>
        <div class="form-row">
          <label>Email<input type="email" name="email" value="<?= e($lead['email']) ?>" maxlength="190"></label>
          <label>Website<input type="text" name="website" value="<?= e($lead['website']) ?>" maxlength="255"></label>
        </div>
        <label>Address<input type="text" name="address" value="<?= e($lead['address']) ?>" maxlength="255"></label>
        <div class="form-row">
          <label>Town<input type="text" name="town" value="<?= e($lead['town']) ?>" maxlength="120"></label>
          <label>Postcode<input type="text" name="postcode" value="<?= e($lead['postcode']) ?>" maxlength="20"></label>
        </div>
        <?php if ($admin): ?>
        <div class="form-row">
          <label>Tier<select name="tier"><?php foreach (TIERS as $t): ?><option value="<?= $t ?>" <?= $lead['tier'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
          <label>Status<select name="dial_status"><?php foreach (DIAL_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $lead['dial_status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
          <label>Assigned to<select name="assigned_to"><option value="">Unassigned</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$lead['assigned_to'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="form-row">
          <label>Callback at<input type="datetime-local" name="callback_at" value="<?= e(to_datetime_local($lead['callback_at'])) ?>"></label>
          <label>Source<input type="text" name="source" value="<?= e($lead['source']) ?>" maxlength="120"></label>
          <label>Batch<input type="text" name="batch_name" value="<?= e($lead['batch_name']) ?>" maxlength="190"></label>
        </div>
        <?php endif; ?>
        <label>Notes<textarea name="notes"><?= e($lead['notes']) ?></textarea></label>
        <label class="field"><input type="checkbox" name="do_not_dial" value="1" <?= $lead['do_not_dial'] ? 'checked' : '' ?>> Do not dial</label>
        <div class="actions">
          <button class="btn btn-primary" type="submit">Save</button>
        </div>
      </form>
      <?php if ($admin): ?>
      <form method="post" class="inline" style="margin-top:1rem;display:block">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this lead and its call history? This cannot be undone.">Delete lead</button>
      </form>
      <?php endif; ?>
    </div>

    <?php if ($custom): ?>
    <div class="card">
      <h3>Custom fields</h3>
      <dl class="kv">
        <?php foreach ($custom as $k => $v): ?>
          <dt><?= e($k) ?></dt><dd><?= e(is_scalar($v) ? $v : json_encode($v)) ?></dd>
        <?php endforeach; ?>
      </dl>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Call history <small class="muted">(<?= count($logs) ?>)</small></h3>
    <?php if (!$logs): ?>
      <p class="muted">No calls logged yet.</p>
    <?php else: ?>
      <ul class="history">
        <?php foreach ($logs as $c): ?>
          <li>
            <span class="when"><?= e(fmt_dt($c['created_at'], 'j M Y H:i')) ?></span>
            <span><span class="pill <?= status_class($c['outcome']) ?>"><?= e($c['outcome']) ?></span> <small class="muted"><?= e($c['user_name'] ?? '') ?></small></span>
            <span class="note"><?= e($c['note']) ?><?= $c['callback_at'] ? ' <small class="muted">· callback ' . e(fmt_dt($c['callback_at'])) . '</small>' : '' ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <dl class="kv">
      <dt>Created</dt><dd><?= e(fmt_dt($lead['created_at'])) ?></dd>
      <dt>Updated</dt><dd><?= e(fmt_dt($lead['updated_at'])) ?></dd>
      <dt>Source</dt><dd><?= e($lead['source'] ?: '—') ?></dd>
      <dt>Lead ID</dt><dd>#<?= (int)$lead['id'] ?></dd>
    </dl>
  </div>
</div>
<?php page_footer();
