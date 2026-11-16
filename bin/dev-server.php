<?php

declare(strict_types=1);

$path = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
if (preg_match('~\A/assets/[a-zA-Z0-9/_-]+\.(?:css|js|jpe?g)\z~', $path) === 1
    && is_file(dirname(__DIR__) . '/public' . $path)) {
    return false;
}

require dirname(__DIR__) . '/public/index.php';
