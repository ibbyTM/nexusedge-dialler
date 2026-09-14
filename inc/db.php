<?php
declare(strict_types=1);

/** Shared PDO connection. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'] ?? 'localhost',
        (int)($c['port'] ?? 3306),
        $c['name'],
        $c['charset'] ?? 'utf8mb4'
    );
    try {
        $pdo = new PDO($dsn, $c['user'] ?? '', $c['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $ex) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Nexus Edge CRM could not connect to the database.\n\n";
        echo "Check the 'db' settings in config.php (host, name, user, pass).\n";
        echo "The exact error has been written to the PHP error log.\n";
        error_log('Nexus Edge CRM DB connection failed: ' . $ex->getMessage());
        exit;
    }
    // Keep MySQL NOW() aligned with the PHP timezone (handles BST/GMT).
    $pdo->prepare('SET time_zone = ?')->execute([date('P')]);
    return $pdo;
}

/** Run a prepared statement and return it. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}
