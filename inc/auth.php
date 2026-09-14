<?php
declare(strict_types=1);

/** Start the session with hardened cookie settings. */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    session_name(config('session_name', 'nexusedge_sess'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

session_boot();

/** Currently logged-in user row, or null. Cached per request. */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return $user = null;
    }
    $row = q_one('SELECT id, name, email, role, active FROM users WHERE id = ?', [$id]);
    if (!$row || !(int)$row['active']) {
        unset($_SESSION['user_id']);
        return $user = null;
    }
    return $user = $row;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php' . ($next !== '' && $next !== '/' ? '?next=' . rawurlencode($next) : ''));
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        page_header('Not allowed');
        echo '<div class="empty"><h2>Not allowed</h2><p>This page is for admins only.</p></div>';
        page_footer();
        exit;
    }
    return $u;
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr($ip, 0, 45);
}

/** True when this IP has hit the failed-login limit. */
function login_rate_limited(): bool
{
    $max = (int)config('login_max_attempts', 5);
    $win = (int)config('login_window_minutes', 15);
    $since = date('Y-m-d H:i:s', time() - $win * 60);
    // Opportunistic cleanup of old rows.
    q('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    $n = (int)q_val(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?',
        [client_ip(), $since]
    );
    return $n >= $max;
}

function login_record_failure(): void
{
    q('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)', [client_ip(), now()]);
}

/** Attempt login. Returns user row on success, null on failure. */
function login_attempt(string $email, string $password): ?array
{
    $row = q_one('SELECT * FROM users WHERE email = ? AND active = 1', [mb_strtolower(trim($email))]);
    if (!$row || !password_verify($password, $row['password_hash'])) {
        // Constant-ish time: still verify against a dummy hash when no user.
        if (!$row) {
            password_verify($password, '$2y$10$abcdefghijklmnopqrstuuJ8p2Q3H8m8u8lZ1WwBbq1x9YHkRz7YW');
        }
        return null;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['id'];
    unset($_SESSION['_csrf']);
    q('DELETE FROM login_attempts WHERE ip = ?', [client_ip()]);
    return $row;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
}

/**
 * Role gate, enforced in SQL.
 * Returns a WHERE fragment (without leading AND) restricting leads to what
 * the current user may see, and appends any needed params.
 * Admins: "1=1". Setters: "l.assigned_to = ?".
 */
function lead_scope_sql(array $user, array &$params, string $alias = 'l'): string
{
    if ($user['role'] === 'admin') {
        return '1=1';
    }
    $params[] = (int)$user['id'];
    return $alias . '.assigned_to = ?';
}
