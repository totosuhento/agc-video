<?php
declare(strict_types=1);
/**
 * Setup lewat terminal (alternatif panel /install). Contoh:
 *   php cli/setup.php admin --password=RahasiaPanjang123
 *   php cli/setup.php site  --host=domain.com --name="Nama Situs" --lang=de --country=DE --preset=software_tutorial \
 *                           [--url=https://domain.com] [--keywords="kata 1,kata 2"] [--channels="@a,@b"] [--email=a@b.c]
 *   php cli/setup.php alias --site=domain.com --host=domain-lain.com
 *   php cli/setup.php global --youtube-key=AIza...
 *   php cli/setup.php list
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';
require ROOT . '/app/presets.php';

$cmd = $argv[1] ?? 'help';
$opt = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $a, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
$list = fn(string $k) => array_values(array_filter(array_map('trim', explode(',', (string)($opt[$k] ?? '')))));

try {
    switch ($cmd) {
        case 'admin':
            $pw = (string)($opt['password'] ?? '');
            if (strlen($pw) < 10) {
                throw new InvalidArgumentException('Password minimal 10 karakter.');
            }
            gset('admin_hash', password_hash($pw, PASSWORD_DEFAULT));
            cron_key();
            echo "Password admin disimpan.\n";
            break;

        case 'global':
            $g = gget('global', []);
            if (isset($opt['youtube-key'])) {
                $g['youtube_api_key'] = trim($opt['youtube-key']);
            }
            gset('global', $g);
            echo "Pengaturan global disimpan.\n";
            break;

        case 'site':
            $host = normalize_host((string)($opt['host'] ?? ''));
            $lang = isset(LANGS[$opt['lang'] ?? '']) ? $opt['lang'] : 'en';
            $country = strtoupper((string)($opt['country'] ?? ''));
            $config = default_config();
            $config['site']['lang'] = $lang;
            $config['site']['country'] = isset(COUNTRIES[$country]) ? $country : '';
            $config = apply_preset($config, (string)($opt['preset'] ?? 'custom'));
            $config['site']['name'] = (string)($opt['name'] ?? $host);
            $config['site']['url'] = rtrim((string)($opt['url'] ?? 'https://' . $host), '/');
            $config['site']['keywords'] = $list('keywords');
            $config['site']['contact_email'] = (string)($opt['email'] ?? 'admin@' . $host);
            $config['youtube']['channels'] = array_values(array_filter(array_map('normalize_channel_ref', $list('channels'))));
            $id = site_create($host, $config);
            echo "Situs dibuat: $id ($host)\n";
            break;

        case 'alias':
            $id = site_id_for_host((string)($opt['site'] ?? '')) ?? (string)($opt['site'] ?? '');
            site_add_host($id, (string)($opt['host'] ?? ''));
            echo "Domain ditambahkan.\n";
            break;

        case 'list':
            foreach (sites_all() as $s) {
                echo $s['id'], "\t", $s['hosts'], "\n";
            }
            break;

        default:
            echo file_get_contents(__FILE__, false, null, 0, 900), "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Gagal: ' . $e->getMessage() . "\n");
    exit(1);
}
