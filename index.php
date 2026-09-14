<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';
redirect(current_user() ? 'dial.php' : 'login.php');
