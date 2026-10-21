<?php
declare(strict_types=1);
/**
 * Tulis deskripsi unik dengan AI (hanya situs yang mengaktifkan AI).
 *   php cli/enrich.php [domain.com]
 * Jalankan via cron tiap 30 menit.
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';
require ROOT . '/app/cron.php';
run_fetch_all($argv[1] ?? null, true);
