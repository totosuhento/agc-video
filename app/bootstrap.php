<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('STORAGE', rtrim((string)(getenv('AGC_STORAGE') ?: ROOT . '/storage'), '/'));
define('AGC_VERSION', '1.0.0');

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('AGC Video membutuhkan PHP 8.1 atau lebih baru (versi server: ' . PHP_VERSION . ").\n");
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

require ROOT . '/app/helpers.php';
require ROOT . '/app/core.php';
require ROOT . '/app/db.php';
require ROOT . '/app/repo.php';
