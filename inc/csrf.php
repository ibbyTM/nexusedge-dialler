<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Hidden input for forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Verify the token on a POST. Stops with 403 on failure. */
function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $known = $_SESSION['_csrf'] ?? '';
    if (!is_string($sent) || $known === '' || !hash_equals($known, $sent)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Invalid or missing form token. Go back, reload the page and try again.\n";
        exit;
    }
}
