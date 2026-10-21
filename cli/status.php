<?php
declare(strict_types=1);
/** Ringkasan semua situs:  php cli/status.php */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

if (!is_installed()) {
    exit("Belum diinstal. Buka /install di browser atau jalankan: php cli/setup.php admin --password=...\n");
}
foreach (sites_all() as $s) {
    Site::activate($s['id']);
    $st = stats();
    printf("\n%s  (%s)\n", cfg('site.name'), $s['hosts']);
    printf("  Bahasa/negara : %s %s | niche: %s | iklan: %s\n", site_lang(), cfg('site.country') ?: '-', cfg('site.niche') ?: '-', ads_mode());
    printf("  Video tampil  : %d (disembunyikan %d) | playlist %d | channel %d | deskripsi AI %d\n",
        $st['videos'], $st['hidden'], $st['series'], $st['channels'], $st['ai']);
    printf("  Fetch terakhir: %s | kuota API hari ini: %d/%d\n", meta_get('last_fetch', '-'), quota_used(), (int)cfg('youtube.daily_quota'));
}
echo "\n";
