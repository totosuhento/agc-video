<?php
declare(strict_types=1);
/**
 * Ambil & perbarui video untuk SEMUA situs (atau satu situs).
 *   php cli/fetch.php                 → semua situs
 *   php cli/fetch.php domain.com      → satu situs
 * Jalankan via cron tiap jam.
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';
require ROOT . '/app/cron.php';
run_fetch_all($argv[1] ?? null);
