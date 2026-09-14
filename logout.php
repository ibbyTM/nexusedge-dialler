<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';
if (is_post()) {
    csrf_verify();
    logout();
}
redirect('login.php');
