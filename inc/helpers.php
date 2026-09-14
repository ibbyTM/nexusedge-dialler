<?php
/**
 * Bootstrap + small helpers. Every page requires this file first.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

/** Load config.php or stop with a clear message (never a stack trace). */
function load_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = APP_ROOT . '/config.php';
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Nexus Edge CRM is not configured.\n\n";
        echo "config.php is missing or unreadable.\n";
        echo "Copy config.example.php to config.php next to this file and fill in your database details.\n";
        exit;
    }
    $loaded = include $path;
    if (!is_array($loaded) || empty($loaded['db']['name'])) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Nexus Edge CRM is not configured.\n\n";
        echo "config.php did not return a valid configuration array.\n";
        echo "Compare it with config.example.php.\n";
        exit;
    }
    $config = $loaded;
    return $config;
}

function config(string $key, $default = null)
{
    $c = load_config();
    return array_key_exists($key, $c) ? $c[$key] : $default;
}

/** HTML-escape for output. */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function get(string $key, $default = '')
{
    return $_GET[$key] ?? $default;
}

function post_str(string $key, int $max = 255): string
{
    $v = $_POST[$key] ?? '';
    if (is_array($v)) {
        return '';
    }
    $v = trim((string)$v);
    return mb_substr($v, 0, $max);
}

/** Flash messages stored in session (type: ok | err | info). */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/** Format a MySQL datetime for display. */
function fmt_dt(?string $dt, string $format = 'D j M Y, H:i'): string
{
    if ($dt === null || $dt === '' || $dt === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($dt);
    return $ts === false ? '' : date($format, $ts);
}

/** Convert an HTML datetime-local value to a MySQL datetime or null. */
function parse_datetime_local(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:00', $ts);
}

/** MySQL datetime -> value for <input type="datetime-local">. */
function to_datetime_local(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $ts = strtotime($dt);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** Build a URL with merged query params (used by pagination and sorting). */
function url_with(array $overrides, ?string $path = null): string
{
    $path = $path ?? basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $q = array_merge($_GET, $overrides);
    $q = array_filter($q, static fn($v) => $v !== null && $v !== '');
    $qs = http_build_query($q);
    return $path . ($qs !== '' ? '?' . $qs : '');
}

/** All dial statuses in the canonical order. */
const DIAL_STATUSES = [
    'New', 'No-Answer', 'Follow-Up', 'Setter Callback', 'Demo Booked',
    'Reschedule', 'Cancelled', 'DQ', 'Invalid',
];

/** Outcomes a setter can pick on the dial screen, in button order. */
const OUTCOMES = [
    'No-Answer', 'Follow-Up', 'Setter Callback', 'Demo Booked',
    'Reschedule', 'Cancelled', 'DQ', 'Invalid',
];

/** Outcomes that require a callback date/time. */
const OUTCOMES_NEED_CALLBACK = ['Setter Callback', 'Follow-Up', 'Reschedule'];

/** Statuses that are never served by the dial queue. */
const TERMINAL_STATUSES = ['Demo Booked', 'Cancelled', 'DQ', 'Invalid'];

const TIERS = ['A', 'B', 'C'];

/** Short CSS class for a status pill. */
function status_class(string $status): string
{
    return 'st-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $status));
}

// ---- Boot ---------------------------------------------------------------
$__config = load_config();
date_default_timezone_set($__config['timezone'] ?? 'Europe/London');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/phone.php';
require_once __DIR__ . '/queue.php';

// ---- Shared lead helpers -----------------------------------------------

/** Active users for assignment dropdowns (setters first, then admins). */
function assignable_users(): array
{
    return q_all("SELECT id, name, role FROM users WHERE active = 1 ORDER BY role = 'admin', name");
}

/**
 * Build the WHERE clause for the leads list / export from GET filters.
 * Always includes the role scope. Returns [sql, params, active_filters].
 */
function leads_filter(array $user, array $in): array
{
    $params = [];
    $where = [lead_scope_sql($user, $params)];
    $f = [
        'status'   => (string)($in['status'] ?? ''),
        'assigned' => (string)($in['assigned'] ?? ''),
        'tier'     => (string)($in['tier'] ?? ''),
        'batch'    => (string)($in['batch'] ?? ''),
        'town'     => (string)($in['town'] ?? ''),
        'dnd'      => (string)($in['dnd'] ?? ''),
        'q'        => trim((string)($in['q'] ?? '')),
    ];
    if ($f['status'] !== '' && in_array($f['status'], DIAL_STATUSES, true)) {
        $where[] = 'l.dial_status = ?';
        $params[] = $f['status'];
    } else {
        $f['status'] = '';
    }
    if ($f['assigned'] === 'none') {
        $where[] = 'l.assigned_to IS NULL';
    } elseif ($f['assigned'] !== '' && ctype_digit($f['assigned'])) {
        $where[] = 'l.assigned_to = ?';
        $params[] = (int)$f['assigned'];
    } else {
        $f['assigned'] = '';
    }
    if ($f['tier'] !== '' && in_array($f['tier'], TIERS, true)) {
        $where[] = 'l.tier = ?';
        $params[] = $f['tier'];
    } else {
        $f['tier'] = '';
    }
    if ($f['batch'] !== '') {
        $where[] = 'l.batch_name = ?';
        $params[] = $f['batch'];
    }
    if ($f['town'] !== '') {
        $where[] = 'l.town LIKE ?';
        $params[] = $f['town'] . '%';
    }
    if ($f['dnd'] === '0' || $f['dnd'] === '1') {
        $where[] = 'l.do_not_dial = ?';
        $params[] = (int)$f['dnd'];
    } else {
        $f['dnd'] = '';
    }
    if ($f['q'] !== '') {
        $like = '%' . $f['q'] . '%';
        $digits = preg_replace('/\D+/', '', $f['q']);
        $where[] = '(l.company_name LIKE ? OR l.contact_name LIKE ? OR l.email LIKE ? OR l.phone_raw LIKE ? OR l.phone_e164 LIKE ?)';
        array_push($params, $like, $like, $like, $like, '%' . ($digits !== '' ? $digits : $f['q']) . '%');
    }
    return [implode(' AND ', $where), $params, $f];
}

/** Base SELECT for lead rows with the assignee name joined. */
const LEAD_SELECT = 'SELECT l.*, u.name AS assigned_name FROM leads l LEFT JOIN users u ON u.id = l.assigned_to';

/** Fetch one lead the current user is allowed to see, or null. */
function find_lead_for_user(array $user, int $id): ?array
{
    $params = [$id];
    $scope = lead_scope_sql($user, $params);
    return q_one(LEAD_SELECT . " WHERE l.id = ? AND $scope", $params);
}
