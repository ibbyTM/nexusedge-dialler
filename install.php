<?php
/**
 * Nexus Edge CRM installer.
 * Creates the database schema and the first admin user, then locks itself.
 * Delete this file after installing.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';

$lock = APP_ROOT . '/storage/install.lock';
$app = config('app_name', 'Nexus Edge CRM');

function install_page(string $title, string $body): never
{
    $app = config('app_name', 'Nexus Edge CRM');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' · ' . e($app) . '</title><link rel="stylesheet" href="assets/style.css?v=1"></head>';
    echo '<body class="auth"><main class="main"><div class="card card-auth"><h1>' . e($app) . '</h1><h2>' . e($title) . '</h2>' . $body . '</div></main></body></html>';
    exit;
}

if (is_file($lock)) {
    http_response_code(403);
    install_page('Already installed', '<p>The installer has already run and is locked.</p><p>Delete <code>install.php</code> from the server now.</p><p><a class="btn" href="login.php">Go to login</a></p>');
}

$schema = [
"CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','setter') NOT NULL DEFAULT 'setter',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS leads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(190) NOT NULL DEFAULT '',
    contact_name VARCHAR(190) NOT NULL DEFAULT '',
    job_title VARCHAR(190) NOT NULL DEFAULT '',
    phone_raw VARCHAR(64) NOT NULL DEFAULT '',
    phone_e164 VARCHAR(20) NULL,
    email VARCHAR(190) NOT NULL DEFAULT '',
    website VARCHAR(255) NOT NULL DEFAULT '',
    address VARCHAR(255) NOT NULL DEFAULT '',
    town VARCHAR(120) NOT NULL DEFAULT '',
    postcode VARCHAR(20) NOT NULL DEFAULT '',
    tier ENUM('A','B','C') NOT NULL DEFAULT 'B',
    source VARCHAR(120) NOT NULL DEFAULT '',
    batch_name VARCHAR(190) NOT NULL DEFAULT '',
    assigned_to INT UNSIGNED NULL,
    dial_status ENUM('New','No-Answer','Follow-Up','Setter Callback','Demo Booked','Reschedule','Cancelled','DQ','Invalid') NOT NULL DEFAULT 'New',
    do_not_dial TINYINT(1) NOT NULL DEFAULT 0,
    last_dial_at DATETIME NULL,
    callback_at DATETIME NULL,
    dial_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    custom_json TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_leads_phone (phone_e164),
    KEY ix_leads_assigned (assigned_to),
    KEY ix_leads_status (dial_status),
    KEY ix_leads_callback (callback_at),
    KEY ix_leads_dnd (do_not_dial),
    KEY ix_leads_last_dial (last_dial_at),
    KEY ix_leads_batch (batch_name),
    CONSTRAINT fk_leads_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS call_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lead_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    outcome ENUM('New','No-Answer','Follow-Up','Setter Callback','Demo Booked','Reschedule','Cancelled','DQ','Invalid') NOT NULL,
    note TEXT NULL,
    callback_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY ix_calls_lead (lead_id),
    KEY ix_calls_user_created (user_id, created_at),
    KEY ix_calls_created (created_at),
    CONSTRAINT fk_calls_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_calls_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS imports (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    batch_name VARCHAR(190) NOT NULL,
    rows_total INT UNSIGNED NOT NULL DEFAULT 0,
    rows_imported INT UNSIGNED NOT NULL DEFAULT 0,
    rows_duplicate INT UNSIGNED NOT NULL DEFAULT 0,
    rows_invalid INT UNSIGNED NOT NULL DEFAULT 0,
    rows_excluded INT UNSIGNED NOT NULL DEFAULT 0,
    resume_offset INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY ix_imports_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY ix_attempts_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$errors = [];
$name = '';
$email = '';

if (is_post()) {
    // Installer runs before any session-based CSRF token could exist, so it uses its own.
    $name = post_str('name', 100);
    $email = mb_strtolower(post_str('email', 190));
    $pass = (string)post('password');
    $pass2 = (string)post('password2');
    $token = (string)post('_install_token');
    if (empty($_SESSION['_install_token']) || !hash_equals($_SESSION['_install_token'], $token)) {
        $errors[] = 'Form token mismatch. Reload and try again.';
    }
    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }
    if (strlen($pass) < 10) {
        $errors[] = 'Password must be at least 10 characters.';
    }
    if ($pass !== $pass2) {
        $errors[] = 'Passwords do not match.';
    }
    if (!$errors) {
        try {
            $pdo = db();
            foreach ($schema as $sql) {
                $pdo->exec($sql);
            }
            $existing = (int)q_val('SELECT COUNT(*) FROM users');
            if ($existing > 0) {
                $errors[] = 'A user already exists. The schema is in place; log in instead.';
            } else {
                q('INSERT INTO users (name, email, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)',
                    [$name, $email, password_hash($pass, PASSWORD_BCRYPT), 'admin', now()]);
            }
            if (!$errors) {
                if (!is_dir(dirname($lock))) {
                    @mkdir(dirname($lock), 0755, true);
                }
                if (@file_put_contents($lock, 'installed ' . now() . "\n") === false) {
                    $errors[] = 'Installed, but could not write storage/install.lock. Create the storage/ folder, make it writable, and create that file by hand. Then delete install.php.';
                } else {
                    unset($_SESSION['_install_token']);
                    install_page('Installed', '<p class="flash flash-ok">Schema created and admin user <strong>' . e($email) . '</strong> added.</p><p><strong>Now delete <code>install.php</code> from the server.</strong></p><p><a class="btn btn-primary" href="login.php">Go to login</a></p>');
                }
            }
        } catch (PDOException $ex) {
            $errors[] = 'Database error: ' . $ex->getMessage();
        }
    }
}

if (empty($_SESSION['_install_token'])) {
    $_SESSION['_install_token'] = bin2hex(random_bytes(16));
}

$body = '';
if ($errors) {
    $body .= '<div class="flash flash-err">' . implode('<br>', array_map('e', $errors)) . '</div>';
}
$body .= '<p>This creates the database tables and the first admin account.</p>';
$body .= '<form method="post" class="form">';
$body .= '<input type="hidden" name="_install_token" value="' . e($_SESSION['_install_token']) . '">';
$body .= '<label>Your name<input type="text" name="name" required maxlength="100" value="' . e($name) . '"></label>';
$body .= '<label>Admin email<input type="email" name="email" required maxlength="190" value="' . e($email) . '"></label>';
$body .= '<label>Password (min 10 chars)<input type="password" name="password" required minlength="10"></label>';
$body .= '<label>Repeat password<input type="password" name="password2" required minlength="10"></label>';
$body .= '<button class="btn btn-primary btn-block" type="submit">Install</button>';
$body .= '</form>';
install_page('Install', $body);
