<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\ConfigurationException;
use Furoeducare\Core\ErrorHandler;
use Furoeducare\Core\Logger;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

$root = dirname(__DIR__);

// Prepare error reporting before loading Composer so a broken install is logged safely.
require $root . '/app/Core/ConfigurationException.php';
require $root . '/app/Core/Logger.php';
require $root . '/app/Core/ErrorHandler.php';

$logFile = getenv('APP_LOG_FILE', true) ?: $root . '/storage/logs/app.log';
$errors = new ErrorHandler(new Logger($logFile));

$errors->run(static function () use ($root, $errors): Response {
    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new ConfigurationException('Composer autoloading is missing. Run composer install.');
    }

    require $autoload;
    $config = Config::load($root);
    $errors->setDevelopmentMode($config->environment === 'development');

    $bootstrap = require $root . '/bootstrap/app.php';
    $router = $bootstrap($config);
    return $router->dispatch(Request::fromServer($_SERVER, $_POST, $_GET, $_FILES));
}, ($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
