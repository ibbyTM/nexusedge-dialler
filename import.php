<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';
$user = require_admin();

const IMPORT_DIR = APP_ROOT . '/storage/imports';
const IMPORT_MAX_BYTES = 20 * 1024 * 1024;

/** CRM fields a CSV column can be mapped to. */
const IMPORT_FIELDS = [
    'company_name' => 'Company name',
    'contact_name' => 'Contact name',
    'job_title'    => 'Job title',
    'phone_raw'    => 'Phone',
    'email'        => 'Email',
    'website'      => 'Website',
    'address'      => 'Address',
    'town'         => 'Town',
    'postcode'     => 'Postcode',
    'source'       => 'Source',
];

/** Header name (normalised) -> field, for auto-guessing the mapping. */
const IMPORT_GUESSES = [
    'company' => 'company_name', 'companyname' => 'company_name', 'business' => 'company_name',
    'businessname' => 'company_name', 'organisation' => 'company_name', 'organization' => 'company_name',
    'agency' => 'company_name', 'agencyname' => 'company_name', 'branch' => 'company_name', 'account' => 'company_name',
    'contact' => 'contact_name', 'contactname' => 'contact_name', 'fullname' => 'contact_name',
    'firstname' => 'contact_name', 'lastname' => 'contact_name', 'surname' => 'contact_name',
    'forename' => 'contact_name', 'person' => 'contact_name', 'owner' => 'contact_name',
    'jobtitle' => 'job_title', 'title' => 'job_title', 'role' => 'job_title', 'position' => 'job_title',
    'phone' => 'phone_raw', 'telephone' => 'phone_raw', 'tel' => 'phone_raw', 'mobile' => 'phone_raw',
    'phonenumber' => 'phone_raw', 'mobilenumber' => 'phone_raw', 'telephonenumber' => 'phone_raw',
    'number' => 'phone_raw', 'cell' => 'phone_raw', 'landline' => 'phone_raw', 'phone1' => 'phone_raw',
    'email' => 'email', 'emailaddress' => 'email', 'mail' => 'email',
    'website' => 'website', 'url' => 'website', 'web' => 'website', 'site' => 'website', 'domain' => 'website',
    'address' => 'address', 'street' => 'address', 'address1' => 'address', 'addressline1' => 'address', 'fulladdress' => 'address',
    'town' => 'town', 'city' => 'town', 'locality' => 'town',
    'postcode' => 'postcode', 'postalcode' => 'postcode', 'zip' => 'postcode', 'zipcode' => 'postcode',
    'source' => 'source',
];

function import_guess(array $headers): array
{
    $map = [];
    foreach ($headers as $i => $h) {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$h) ?? '');
        $map[$i] = IMPORT_GUESSES[$key] ?? '';
    }
    // "name" on its own: company if nothing else is the company, else contact.
    foreach ($headers as $i => $h) {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$h) ?? '');
        if ($key === 'name') {
            $map[$i] = in_array('company_name', $map, true) ? 'contact_name' : 'company_name';
        }
    }
    return $map;
}

function import_meta_path(string $token): string
{
    return IMPORT_DIR . '/' . $token . '.json';
}

function import_csv_path(string $token): string
{
    return IMPORT_DIR . '/' . $token . '.csv';
}

function import_load(string $token, int $userId): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $p = import_meta_path($token);
    if (!is_file($p)) {
        return null;
    }
    $m = json_decode((string)file_get_contents($p), true);
    if (!is_array($m) || (int)($m['user_id'] ?? 0) !== $userId) {
        return null;
    }
    return $m;
}

function import_save(array $m): void
{
    file_put_contents(import_meta_path($m['token']), json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** Remove import files older than a day. */
function import_cleanup(): void
{
    foreach (glob(IMPORT_DIR . '/*.{csv,json}', GLOB_BRACE) ?: [] as $f) {
        if (filemtime($f) < time() - 86400) {
            @unlink($f);
        }
    }
}

/** Guess the delimiter from the header line. */
function import_detect_delimiter(string $line): string
{
    $best = ',';
    $bestCount = -1;
    foreach ([',', ';', "\t", '|'] as $d) {
        $n = substr_count($line, $d);
        if ($n > $bestCount) {
            $best = $d;
            $bestCount = $n;
        }
    }
    return $best;
}

/** Open the CSV, skip the BOM, return handle. */
function import_open(string $path)
{
    $fh = fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    return $fh;
}

function import_cell(?string $v): string
{
    $v = trim((string)$v);
    if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
        $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
    return $v;
}

function import_row_is_blank(array $row): bool
{
    foreach ($row as $c) {
        if (trim((string)$c) !== '') {
            return false;
        }
    }
    return true;
}

function import_json(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function import_blocked(string $company, array $blocklist): bool
{
    if ($company === '') {
        return false;
    }
    foreach ($blocklist as $bad) {
        $bad = trim((string)$bad);
        if ($bad !== '' && mb_stripos($company, $bad) !== false) {
            return true;
        }
    }
    return false;
}

if (!is_dir(IMPORT_DIR)) {
    @mkdir(IMPORT_DIR, 0755, true);
}

$token = (string)(is_post() ? post('token', '') : get('token', ''));
$step = (string)get('step', '');
$errors = [];

// =====================================================================
// POST handlers
// =====================================================================
if (is_post()) {
    csrf_verify();
    $action = (string)post('action', '');

    // ---- Step 1: upload ----
    if ($action === 'upload') {
        import_cleanup();
        $f = $_FILES['csv'] ?? null;
        if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Choose a CSV file to upload.';
        } elseif ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'That file is bigger than the server allows. Max is 20MB (check upload_max_filesize in your PHP settings).';
        } elseif ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed (code ' . (int)$f['error'] . '). Try again.';
        } elseif ((int)$f['size'] > IMPORT_MAX_BYTES) {
            $errors[] = 'That file is bigger than 20MB.';
        } elseif (!is_uploaded_file($f['tmp_name'])) {
            $errors[] = 'Upload failed. Try again.';
        } elseif (!is_writable(IMPORT_DIR)) {
            $errors[] = 'The storage/imports folder is not writable. Fix its permissions in cPanel File Manager.';
        } else {
            $token = bin2hex(random_bytes(16));
            $dest = import_csv_path($token);
            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                $errors[] = 'Could not save the uploaded file.';
            } else {
                $fh = import_open($dest);
                $firstLine = $fh ? (string)fgets($fh) : '';
                $delim = import_detect_delimiter($firstLine);
                if ($fh) {
                    fclose($fh);
                }
                $fh = import_open($dest);
                $headers = $fh ? fgetcsv($fh, 0, $delim, '"', '') : false;
                if (!$headers || import_row_is_blank($headers)) {
                    if ($fh) {
                        fclose($fh);
                    }
                    @unlink($dest);
                    $errors[] = 'The file has no header row.';
                } else {
                    $headers = array_map('import_cell', $headers);
                    $preview = [];
                    $total = 0;
                    $dataStart = ftell($fh);
                    while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
                        if ($row === [null] || import_row_is_blank($row)) {
                            continue;
                        }
                        $total++;
                        if (count($preview) < 5) {
                            $preview[] = array_map('import_cell', $row);
                        }
                    }
                    fclose($fh);
                    $filename = basename((string)$f['name']);
                    $meta = [
                        'token'      => $token,
                        'user_id'    => (int)$user['id'],
                        'filename'   => $filename,
                        'delimiter'  => $delim,
                        'headers'    => $headers,
                        'preview'    => $preview,
                        'rows_total' => $total,
                        'data_start' => $dataStart,
                        'offset'     => $dataStart,
                        'step'       => 'map',
                        'mapping'    => [],
                        'batch_name' => preg_replace('/\.[a-z0-9]+$/i', '', $filename) . ' ' . date('Y-m-d'),
                        'tier'       => 'B',
                        'assign_to'  => 0,
                        'import_id'  => 0,
                        'processed'  => 0,
                        'imported'   => 0,
                        'invalid'    => 0,
                        'duplicate'  => 0,
                        'excluded'   => 0,
                        'created_at' => now(),
                    ];
                    import_save($meta);
                    redirect('import.php?token=' . $token . '&step=map');
                }
            }
        }
    }

    // ---- Step 2: save mapping ----
    if ($action === 'map') {
        $meta = import_load($token, (int)$user['id']);
        if (!$meta || $meta['step'] !== 'map') {
            flash('err', 'That import session has expired. Upload the file again.');
            redirect('import.php');
        }
        $posted = (array)post('map', []);
        $mapping = [];
        $mappedFields = [];
        foreach ($meta['headers'] as $i => $h) {
            $v = (string)($posted[$i] ?? '');
            if ($v === 'custom' || isset(IMPORT_FIELDS[$v])) {
                $mapping[$i] = $v;
                if ($v !== 'custom') {
                    $mappedFields[] = $v;
                }
            } else {
                $mapping[$i] = '';
            }
        }
        $batch = post_str('batch_name', 190);
        $tier = post_str('tier', 1);
        $assign = (int)post('assign_to', 0);
        if (!in_array('phone_raw', $mappedFields, true)) {
            $errors[] = 'Map one column to Phone. Without it every lead would be invalid.';
        }
        if ($batch === '') {
            $errors[] = 'Batch name is required.';
        }
        if (!in_array($tier, TIERS, true)) {
            $errors[] = 'Choose a tier.';
        }
        if ($assign > 0 && !q_one('SELECT id FROM users WHERE id = ? AND active = 1', [$assign])) {
            $errors[] = 'That setter does not exist.';
        }
        $meta['mapping'] = $mapping;
        $meta['batch_name'] = $batch;
        $meta['tier'] = $tier;
        $meta['assign_to'] = $assign;
        if (!$errors) {
            q('INSERT INTO imports (user_id, filename, batch_name, rows_total, created_at) VALUES (?, ?, ?, ?, ?)',
                [(int)$user['id'], $meta['filename'], $batch, (int)$meta['rows_total'], now()]);
            $meta['import_id'] = (int)db()->lastInsertId();
            $meta['step'] = 'run';
            import_save($meta);
            redirect('import.php?token=' . $token . '&step=run');
        }
        import_save($meta);
        $step = 'map';
    }

    // ---- Step 3: process one chunk (called by fetch) ----
    if ($action === 'chunk') {
        $meta = import_load($token, (int)$user['id']);
        if (!$meta || !in_array($meta['step'], ['run', 'done'], true)) {
            import_json(['error' => 'Import session not found. Upload the file again.']);
        }
        if ($meta['step'] === 'done') {
            import_json(['done' => true, 'processed' => $meta['processed'], 'total' => $meta['rows_total']]);
        }
        $lockFh = fopen(import_meta_path($token) . '.lock', 'c');
        if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
            import_json(['error' => 'Another import request is still running. Reload to resume.']);
        }
        set_time_limit(120);
        ignore_user_abort(true);
        $chunk = max(50, (int)config('import_chunk_size', 500));
        $blocklist = (array)config('blocklist', []);
        $fh = import_open(import_csv_path($token));
        if (!$fh) {
            import_json(['error' => 'The uploaded file is gone. Upload it again.']);
        }
        fseek($fh, (int)$meta['offset']);
        $delim = $meta['delimiter'];
        $mapping = $meta['mapping'];
        $headers = $meta['headers'];

        // Parse a chunk of rows into lead arrays.
        $pending = [];   // rows to insert
        $seen = [];      // phones seen in this chunk
        $n = 0;
        while ($n < $chunk && ($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if ($row === [null] || import_row_is_blank($row)) {
                continue;
            }
            $n++;
            $lead = array_fill_keys(array_keys(IMPORT_FIELDS), '');
            $custom = [];
            foreach ($mapping as $i => $target) {
                if ($target === '') {
                    continue;
                }
                $val = import_cell($row[$i] ?? '');
                if ($target === 'custom') {
                    $custom[(string)$headers[$i]] = $val;
                } elseif ($val !== '') {
                    $lead[$target] = $lead[$target] === '' ? $val : $lead[$target] . ' ' . $val;
                }
            }
            if (import_blocked($lead['company_name'], $blocklist)) {
                $meta['excluded']++;
                continue;
            }
            $e164 = normalise_phone($lead['phone_raw']);
            if ($e164 !== null) {
                if (isset($seen[$e164])) {
                    $meta['duplicate']++;
                    continue;
                }
                $seen[$e164] = true;
            }
            $lead['phone_e164'] = $e164;
            $lead['custom'] = $custom;
            $pending[] = $lead;
        }
        $meta['offset'] = ftell($fh);
        $eof = feof($fh) || $n < $chunk;
        fclose($fh);

        // Dedupe against the database in one query.
        $phones = array_values(array_filter(array_column($pending, 'phone_e164')));
        $existing = [];
        if ($phones) {
            $ph = implode(',', array_fill(0, count($phones), '?'));
            foreach (q_all("SELECT phone_e164 FROM leads WHERE phone_e164 IN ($ph)", $phones) as $r) {
                $existing[$r['phone_e164']] = true;
            }
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO leads (company_name, contact_name, job_title, phone_raw, phone_e164, email, website, address, town, postcode, tier, source, batch_name, assigned_to, dial_status, custom_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ts = now();
            foreach ($pending as $lead) {
                if ($lead['phone_e164'] !== null && isset($existing[$lead['phone_e164']])) {
                    $meta['duplicate']++;
                    continue;
                }
                $status = $lead['phone_e164'] === null ? 'Invalid' : 'New';
                try {
                    $ins->execute([
                        mb_substr($lead['company_name'], 0, 190),
                        mb_substr($lead['contact_name'], 0, 190),
                        mb_substr($lead['job_title'], 0, 190),
                        mb_substr($lead['phone_raw'], 0, 64),
                        $lead['phone_e164'],
                        mb_substr($lead['email'], 0, 190),
                        mb_substr($lead['website'], 0, 255),
                        mb_substr($lead['address'], 0, 255),
                        mb_substr($lead['town'], 0, 120),
                        mb_substr($lead['postcode'], 0, 20),
                        $meta['tier'],
                        mb_substr($lead['source'], 0, 120),
                        $meta['batch_name'],
                        $meta['assign_to'] > 0 ? (int)$meta['assign_to'] : null,
                        $status,
                        $lead['custom'] ? json_encode($lead['custom'], JSON_UNESCAPED_UNICODE) : null,
                        $ts,
                        $ts,
                    ]);
                } catch (PDOException $ex) {
                    // Unique index race: treat as a duplicate rather than failing the import.
                    if ((string)$ex->getCode() === '23000') {
                        $meta['duplicate']++;
                        continue;
                    }
                    throw $ex;
                }
                if ($status === 'Invalid') {
                    $meta['invalid']++;
                } else {
                    $meta['imported']++;
                }
            }
            $meta['processed'] += $n;
            if ($eof) {
                $meta['step'] = 'done';
                $meta['finished_at'] = now();
                q('UPDATE imports SET rows_total = ?, rows_imported = ?, rows_duplicate = ?, rows_invalid = ?, rows_excluded = ? WHERE id = ?',
                    [(int)$meta['processed'], (int)$meta['imported'], (int)$meta['duplicate'], (int)$meta['invalid'], (int)$meta['excluded'], (int)$meta['import_id']]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flock($lockFh, LOCK_UN);
            import_json(['error' => 'Database error while importing: ' . $ex->getMessage()]);
        }
        import_save($meta);
        if ($meta['step'] === 'done') {
            @unlink(import_csv_path($token));
            @unlink(import_meta_path($token) . '.lock');
        }
        flock($lockFh, LOCK_UN);
        import_json([
            'done'      => $meta['step'] === 'done',
            'processed' => $meta['processed'],
            'total'     => $meta['rows_total'],
            'imported'  => $meta['imported'],
            'invalid'   => $meta['invalid'],
            'duplicate' => $meta['duplicate'],
            'excluded'  => $meta['excluded'],
        ]);
    }
}

// =====================================================================
// GET views
// =====================================================================
$meta = $token !== '' ? import_load($token, (int)$user['id']) : null;
if ($token !== '' && !$meta && !$errors) {
    flash('err', 'That import session has expired. Upload the file again.');
    redirect('import.php');
}
if ($meta && $step === '') {
    $step = $meta['step'];
}
if ($meta && $step === 'map' && $meta['step'] !== 'map') {
    $step = $meta['step'];
}
if ($meta && $step === 'run' && $meta['step'] === 'done') {
    $step = 'done';
}

page_header('Import');

// ---------------------------------------------------------------- Step 1
if (!$meta) {
    $recent = q_all('SELECT i.*, u.name AS user_name FROM imports i LEFT JOIN users u ON u.id = i.user_id ORDER BY i.id DESC LIMIT 10');
    ?>
<h1>Import leads</h1>
<?php foreach ($errors as $err): ?><div class="flash flash-err"><?= e($err) ?></div><?php endforeach; ?>
<div class="grid-2">
  <div class="card">
    <h3>Step 1 of 3 · Upload a CSV</h3>
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= IMPORT_MAX_BYTES ?>">
      <label>CSV file (up to 20MB; comma, semicolon or tab separated)<input type="file" name="csv" accept=".csv,.txt,text/csv,text/plain" required></label>
      <button class="btn btn-primary" type="submit">Upload and map columns</button>
    </form>
    <p class="muted" style="margin-top:.75rem">The first row must be a header row. Rows are deduplicated on phone number. Phone numbers that are not valid UK numbers are imported with status Invalid so nothing is lost. A <a href="leads-template.csv">template CSV</a> is available.</p>
  </div>
  <div class="card">
    <h3>Recent imports</h3>
    <?php if (!$recent): ?>
      <p class="muted">No imports yet.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="table-tight">
      <thead><tr><th>When</th><th>Batch</th><th class="num">Rows</th><th class="num">Imported</th><th class="num">Invalid</th><th class="num">Dupes</th><th class="num">Excluded</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= e(fmt_dt($r['created_at'], 'j M H:i')) ?></td>
          <td><a href="leads.php?batch=<?= e(rawurlencode($r['batch_name'])) ?>"><?= e($r['batch_name']) ?></a></td>
          <td class="num"><?= (int)$r['rows_total'] ?></td>
          <td class="num"><?= (int)$r['rows_imported'] ?></td>
          <td class="num"><?= (int)$r['rows_invalid'] ?></td>
          <td class="num"><?= (int)$r['rows_duplicate'] ?></td>
          <td class="num"><?= (int)$r['rows_excluded'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>
<?php
    page_footer();
    exit;
}

// ---------------------------------------------------------------- Step 2
if ($step === 'map') {
    $guess = $meta['mapping'] ?: import_guess($meta['headers']);
    $users = assignable_users();
    ?>
<h1>Import leads</h1>
<?php foreach ($errors as $err): ?><div class="flash flash-err"><?= e($err) ?></div><?php endforeach; ?>
<div class="card stack">
  <div>
    <h3>Step 2 of 3 · Map columns</h3>
    <p class="muted"><strong><?= e($meta['filename']) ?></strong> · <?= number_format((int)$meta['rows_total']) ?> data rows · delimiter: <code><?= $meta['delimiter'] === "\t" ? 'tab' : e($meta['delimiter']) ?></code></p>
  </div>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="map">
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="table-wrap preview-wrap">
      <table class="table-tight map-grid">
        <thead>
          <tr><?php foreach ($meta['headers'] as $i => $h): ?><th><?= e($h !== '' ? $h : 'Column ' . ($i + 1)) ?></th><?php endforeach; ?></tr>
          <tr>
            <?php foreach ($meta['headers'] as $i => $h): ?>
            <th>
              <select name="map[<?= (int)$i ?>]" aria-label="Map column <?= e($h) ?>">
                <option value="">Ignore</option>
                <option value="custom" <?= ($guess[$i] ?? '') === 'custom' ? 'selected' : '' ?>>Keep as custom field</option>
                <?php foreach (IMPORT_FIELDS as $k => $label): ?>
                  <option value="<?= $k ?>" <?= ($guess[$i] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($meta['preview'] as $row): ?>
            <tr><?php foreach ($meta['headers'] as $i => $h): ?><td><?= e(mb_substr((string)($row[$i] ?? ''), 0, 40)) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="muted">Two columns mapped to the same field are joined with a space (e.g. first name + last name).</p>
    <div class="form-row">
      <label>Batch name<input type="text" name="batch_name" value="<?= e($meta['batch_name']) ?>" required maxlength="190"></label>
      <label>Tier<select name="tier"><?php foreach (TIERS as $t): ?><option value="<?= $t ?>" <?= $meta['tier'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
      <label>Assign all to<select name="assign_to"><option value="0">Leave unassigned</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$meta['assign_to'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="actions">
      <button class="btn btn-primary" type="submit">Import <?= number_format((int)$meta['rows_total']) ?> rows</button>
      <a class="btn btn-ghost" href="import.php">Cancel</a>
    </div>
  </form>
</div>
<?php
    page_footer();
    exit;
}

// ---------------------------------------------------------------- Step 3
if ($step === 'run') {
    ?>
<h1>Import leads</h1>
<div class="card stack" id="import-progress" data-token="<?= e($token) ?>" data-csrf="<?= e(csrf_token()) ?>">
  <div>
    <h3>Step 3 of 3 · Importing</h3>
    <p class="muted"><?= e($meta['filename']) ?> → batch <strong><?= e($meta['batch_name']) ?></strong></p>
  </div>
  <div class="progress"><div style="width:<?= $meta['rows_total'] > 0 ? (int)round($meta['processed'] / $meta['rows_total'] * 100) : 0 ?>%"></div></div>
  <p id="import-status">Starting… keep this page open.</p>
  <noscript><div class="flash flash-err">JavaScript is required to run the import.</div></noscript>
</div>
<?php
    page_footer();
    exit;
}

// ---------------------------------------------------------------- Done
?>
<h1>Import leads</h1>
<div class="card stack">
  <div>
    <h3>Import complete</h3>
    <p class="muted"><?= e($meta['filename']) ?> → batch <strong><?= e($meta['batch_name']) ?></strong></p>
  </div>
  <div class="stats">
    <div class="stat"><div class="v"><?= number_format((int)$meta['imported']) ?></div><div class="l">Imported</div></div>
    <div class="stat"><div class="v"><?= number_format((int)$meta['invalid']) ?></div><div class="l">Imported as Invalid</div></div>
    <div class="stat"><div class="v"><?= number_format((int)$meta['duplicate']) ?></div><div class="l">Duplicates skipped</div></div>
    <div class="stat"><div class="v"><?= number_format((int)$meta['excluded']) ?></div><div class="l">Excluded (blocklist)</div></div>
    <div class="stat"><div class="v"><?= number_format((int)$meta['processed']) ?></div><div class="l">Rows read</div></div>
  </div>
  <div class="actions">
    <a class="btn btn-primary" href="leads.php?batch=<?= e(rawurlencode($meta['batch_name'])) ?>">View this batch</a>
    <?php if ((int)$meta['invalid'] > 0): ?><a class="btn" href="leads.php?batch=<?= e(rawurlencode($meta['batch_name'])) ?>&status=Invalid">View invalid rows</a><?php endif; ?>
    <a class="btn btn-ghost" href="import.php">Import another file</a>
  </div>
</div>
<?php page_footer();
