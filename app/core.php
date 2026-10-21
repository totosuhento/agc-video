<?php
declare(strict_types=1);

/*
 * Inti multi-situs.
 *   storage/main.sqlite          → admin, daftar situs & domain, pengaturan global, kuota API
 *   storage/sites/<id>/          → database.sqlite, cache/, logs/ milik satu situs
 * Situs dipilih dari nama domain (Host) pengunjung.
 */

/* ---------- Database utama ---------- */

function main_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    ensure_dir(STORAGE);
    $pdo = new PDO('sqlite:' . STORAGE . '/main.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=5000;');
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 1) {
        $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);
        CREATE TABLE IF NOT EXISTS sites (id TEXT PRIMARY KEY, primary_host TEXT NOT NULL, created_at TEXT);
        CREATE TABLE IF NOT EXISTS hosts (host TEXT PRIMARY KEY, site_id TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS quota (k TEXT PRIMARY KEY, used INTEGER DEFAULT 0);
        PRAGMA user_version = 1;
        SQL);
    }
    return $pdo;
}

function mq(string $sql, array $params = []): PDOStatement
{
    $st = main_db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function gset(string $k, mixed $v): void
{
    mq('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v',
        [$k, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

function gget(string $k, mixed $default = null): mixed
{
    $v = mq('SELECT v FROM settings WHERE k = ?', [$k])->fetchColumn();
    return $v === false ? $default : json_decode((string)$v, true);
}

function is_installed(): bool
{
    return (string)gget('admin_hash', '') !== '';
}

/* ---------- Domain & situs ---------- */

function normalize_host(string $host): string
{
    $h = strtolower(trim($host));
    $h = (string)preg_replace('~^[a-z]+://~', '', $h);
    $h = explode('/', $h)[0];
    $h = (string)preg_replace('/:\d+$/', '', $h);       // buang port
    $h = rtrim($h, '.');
    if (str_starts_with($h, 'www.')) {
        $h = substr($h, 4);
    }
    return $h;
}

function valid_host(string $host): bool
{
    return $host !== '' && strlen($host) <= 253 && (bool)preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host);
}

function request_host(): string
{
    return normalize_host((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
}

function request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || strtolower((string)($_SERVER['HTTP_CF_VISITOR'] ?? '')) === '{"scheme":"https"}'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function site_id_for_host(string $host): ?string
{
    $id = mq('SELECT site_id FROM hosts WHERE host = ?', [normalize_host($host)])->fetchColumn();
    return $id === false ? null : (string)$id;
}

function sites_all(): array
{
    return mq('SELECT s.*, (SELECT group_concat(host, \', \') FROM hosts h WHERE h.site_id = s.id) AS hosts
               FROM sites s ORDER BY s.created_at')->fetchAll();
}

function site_dir(string $id): string
{
    return STORAGE . '/sites/' . $id;
}

/** Buat situs baru untuk sebuah domain. Mengembalikan id situs. */
function site_create(string $host, array $config): string
{
    $host = normalize_host($host);
    if (!valid_host($host)) {
        throw new InvalidArgumentException('Nama domain tidak valid: ' . $host);
    }
    if (site_id_for_host($host) !== null) {
        throw new InvalidArgumentException('Domain sudah terdaftar: ' . $host);
    }
    $id = (string)preg_replace('/[^a-z0-9.-]/', '_', $host);
    $base = $id;
    $n = 2;
    while (mq('SELECT 1 FROM sites WHERE id = ?', [$id])->fetchColumn()) {
        $id = $base . '-' . $n++;
    }
    ensure_dir(site_dir($id));
    mq('INSERT INTO sites (id, primary_host, created_at) VALUES (?, ?, ?)', [$id, $host, now()]);
    mq('INSERT INTO hosts (host, site_id) VALUES (?, ?)', [$host, $id]);
    $site = Site::activate($id);
    $site->save(array_replace_recursive(default_config(), $config));
    return $id;
}

function site_add_host(string $siteId, string $host): void
{
    $host = normalize_host($host);
    if (!valid_host($host)) {
        throw new InvalidArgumentException('Nama domain tidak valid: ' . $host);
    }
    $owner = site_id_for_host($host);
    if ($owner !== null && $owner !== $siteId) {
        throw new InvalidArgumentException('Domain sudah dipakai situs lain: ' . $host);
    }
    mq('INSERT OR IGNORE INTO hosts (host, site_id) VALUES (?, ?)', [$host, $siteId]);
}

function site_remove_host(string $siteId, string $host): void
{
    mq('DELETE FROM hosts WHERE host = ? AND site_id = ?', [normalize_host($host), $siteId]);
}

/** Lepas situs dari daftar. Folder datanya dipindah ke storage/trash (tidak dihapus permanen). */
function site_detach(string $siteId): void
{
    mq('DELETE FROM hosts WHERE site_id = ?', [$siteId]);
    mq('DELETE FROM sites WHERE id = ?', [$siteId]);
    if (Site::$current && Site::$current->id === $siteId) {
        Site::$current = null;
    }
    $dir = site_dir($siteId);
    if (is_dir($dir)) {
        ensure_dir(STORAGE . '/trash');
        @rename($dir, STORAGE . '/trash/' . $siteId . '-' . gmdate('YmdHis'));
    }
}

/* ---------- Konteks situs aktif ---------- */

final class Site
{
    public static ?Site $current = null;
    public ?PDO $pdo = null;
    public array $cfg = [];
    /** @var array<string, string>|null */
    public ?array $lang = null;

    private function __construct(public readonly string $id) {}

    public static function activate(string $id): Site
    {
        $s = new Site($id);
        $s->dir();
        self::$current = $s;
        $row = q('SELECT v FROM settings WHERE k = ?', ['config'])->fetchColumn();
        $stored = $row === false ? [] : (json_decode((string)$row, true) ?: []);
        $s->cfg = array_replace_recursive(default_config(), $stored);
        if (trim((string)$s->cfg['site']['url']) === '') {
            $host = (string)mq('SELECT primary_host FROM sites WHERE id = ?', [$id])->fetchColumn();
            $s->cfg['site']['url'] = 'https://' . $host;
        }
        return $s;
    }

    public function dir(string $sub = ''): string
    {
        $d = site_dir($this->id) . ($sub !== '' ? '/' . $sub : '');
        ensure_dir($d);
        return $d;
    }

    public function save(array $config): void
    {
        // Daftar (channels, keywords, ...) diganti utuh, bukan digabung per indeks
        $merged = array_replace_recursive(default_config(), $config);
        foreach (['youtube' => ['channels', 'include_keywords', 'exclude_keywords', 'audio_langs'], 'site' => ['keywords'], 'share' => ['networks']] as $sec => $keys) {
            foreach ($keys as $k) {
                if (isset($config[$sec][$k])) {
                    $merged[$sec][$k] = array_values((array)$config[$sec][$k]);
                }
            }
        }
        if (isset($config['sources'])) {
            $merged['sources'] = array_values((array)$config['sources']);
        }
        q('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v',
            ['config', json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $this->cfg = $merged;
        $this->lang = null;
    }
}

function current_site(): Site
{
    if (!Site::$current) {
        throw new RuntimeException('Tidak ada situs aktif');
    }
    return Site::$current;
}

/** Nilai pengaturan situs aktif, dengan cadangan ke pengaturan global (API key, AI). */
function cfg(string $path, mixed $default = null): mixed
{
    $v = Site::$current?->cfg ?? default_config();
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            $v = null;
            break;
        }
        $v = $v[$k];
    }
    if (($v === null || $v === '') && in_array($path, ['youtube.api_key', 'ai.api_key', 'ai.provider', 'ai.model', 'ai.endpoint'], true)) {
        $v = gget('global', [])[str_replace('.', '_', $path)] ?? null;
    }
    return $v ?? $default;
}

function default_config(): array
{
    return [
        'site' => [
            'name' => 'Video Hub', 'tagline' => '', 'url' => '', 'lang' => 'en', 'country' => '',
            'niche' => '', 'item' => '', 'keywords' => [], 'intro' => '',
            'theme' => 'dark', 'accent' => '#ff4d6d', 'preset' => 'custom',
            'group' => '',                // situs saudara beda bahasa (hreflang) memakai nama grup yang sama
            'contact_email' => '', 'per_page' => 24, 'cache_ttl' => 600,
        ],
        'youtube' => [
            'api_key' => '', 'channels' => [], 'include_keywords' => [], 'exclude_keywords' => ['#shorts'],
            'min_duration' => 120, 'initial_pages' => 20, 'pages_per_run' => 2, 'series_per_run' => 20,
            'playlist_refresh_hours' => 24, 'refresh_per_run' => 1000, 'daily_quota' => 9000,
            'audio_langs' => [],          // kosong = semua bahasa; mis. ['de'] = hanya video berbahasa Jerman
        ],
        'player' => ['subtitles' => 'auto'],   // auto | always | off
        'ai' => ['enabled' => false, 'provider' => '', 'api_key' => '', 'model' => '', 'endpoint' => '', 'per_run' => 30, 'articles_per_run' => 2],
        'ads' => [
            'mode' => 'adsense',          // adsense | network | custom
            'adsense_client' => '',       // ca-pub-xxxxxxxxxxxxxxxx
            'label' => true,
            'head' => '', 'top' => '', 'below_player' => '', 'in_list' => '', 'in_article' => '', 'sidebar' => '', 'footer' => '',
            'body_end' => '',             // popunder / social bar / smartlink (khusus mode network/custom)
            'cta_enabled' => false, 'cta_text' => '', 'cta_note' => '', 'cta_url' => '',
            // CTA artikel default (bisa ditimpa per artikel)
            'acta_enabled' => false, 'acta_title' => '', 'acta_text' => '', 'acta_url' => '', 'acta_note' => '',
            'acta_pos' => 'both',         // middle | end | both
            'acta_in_adsense' => false,   // izinkan di mode AdSense (hanya untuk afiliasi produk resmi)
            'ads_txt' => '',
        ],
        'sources' => [],                  // sumber selain YouTube: [{type, value}]
        'seo' => ['video_index' => 'smart', 'indexnow' => true, 'google_verify' => '', 'bing_verify' => '', 'naver_verify' => '', 'yandex_verify' => ''],
        'share' => ['enabled' => true, 'networks' => ['whatsapp', 'telegram', 'facebook', 'x', 'pinterest', 'reddit']],
        'social' => ['telegram_enabled' => false, 'telegram_token' => '', 'telegram_chat' => '', 'telegram_per_run' => 5],
        'push' => ['head' => '', 'sw_name' => '', 'sw_code' => ''],
        'legal' => ['name' => '', 'address' => ''],
        'analytics' => '',
    ];
}

/* ---------- Kuota YouTube API (global per API key, reset tengah malam waktu Pasifik) ---------- */

function quota_key(): string
{
    $day = (new DateTime('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
    return $day . ':' . substr(sha1((string)cfg('youtube.api_key', '')), 0, 12);
}

function quota_used(): int
{
    return (int)mq('SELECT used FROM quota WHERE k = ?', [quota_key()])->fetchColumn();
}

function quota_add(int $units): void
{
    mq('INSERT INTO quota (k, used) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET used = used + excluded.used', [quota_key(), $units]);
}

/* ---------- Lain-lain ---------- */

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Folder tidak bisa dibuat (cek izin tulis): ' . $dir);
    }
}

function random_token(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function cron_key(): string
{
    $k = (string)gget('cron_key', '');
    if ($k === '') {
        $k = random_token(12);
        gset('cron_key', $k);
    }
    return $k;
}

/* ---------- Situs saudara (beda bahasa, satu grup) untuk hreflang ---------- */

/** Baca pengaturan situs lain tanpa mengganti situs aktif. */
function site_peek(string $id): ?array
{
    $f = site_dir($id) . '/database.sqlite';
    if (!is_file($f)) {
        return null;
    }
    try {
        $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $raw = $pdo->query("SELECT v FROM settings WHERE k = 'config'")->fetchColumn();
        $cfg = array_replace_recursive(default_config(), $raw ? (json_decode((string)$raw, true) ?: []) : []);
        return ['pdo' => $pdo, 'cfg' => $cfg];
    } catch (Throwable) {
        return null;
    }
}

/**
 * Situs dalam grup yang sama dengan situs aktif (termasuk dirinya).
 * @return array<int, array{id:string, lang:string, tag:string, url:string, pdo:PDO}>
 */
function sister_sites(): array
{
    static $memo = [];
    $site = Site::$current;
    $group = trim((string)cfg('site.group', ''));
    if (!$site || $group === '') {
        return [];
    }
    if (isset($memo[$site->id])) {
        return $memo[$site->id];
    }
    $out = [];
    foreach (mq('SELECT id, primary_host FROM sites ORDER BY created_at')->fetchAll() as $row) {
        $p = site_peek($row['id']);
        if (!$p || trim((string)$p['cfg']['site']['group']) !== $group) {
            continue;
        }
        $lang = (string)$p['cfg']['site']['lang'];
        $c = strtoupper((string)$p['cfg']['site']['country']);
        $out[] = [
            'id' => $row['id'], 'lang' => $lang,
            'tag' => $lang === 'zh' ? 'zh-Hans' : $lang . (preg_match('/^[A-Z]{2}$/', $c) ? '-' . $c : ''),
            'url' => rtrim((string)($p['cfg']['site']['url'] ?: 'https://' . $row['primary_host']), '/'),
            'pdo' => $p['pdo'],
        ];
    }
    return $memo[$site->id] = count($out) > 1 ? $out : [];
}

/**
 * Link hreflang untuk halaman ini. $path: '/', '/latest', ... ; $videoId: untuk halaman video.
 * @return array<int, array{0:string, 1:string}> [hreflang, url]
 */
function hreflang_links(string $path, ?string $videoId = null): array
{
    $sisters = sister_sites();
    if (!$sisters) {
        return [];
    }
    $links = [];
    foreach ($sisters as $s) {
        if ($videoId !== null) {
            $st = $s['pdo']->prepare('SELECT slug FROM videos WHERE id = ? AND is_active = 1');
            $st->execute([$videoId]);
            $slug = $st->fetchColumn();
            if ($slug === false) {
                continue;
            }
            $links[] = [$s['tag'], $s['url'] . '/video/' . $slug];
        } else {
            $links[] = [$s['tag'], $s['url'] . $path];
        }
    }
    if (count($links) < 2) {
        return [];
    }
    foreach ($sisters as $i => $s) {
        if ($s['lang'] === 'en' && isset($links[$i]) && $videoId === null) {
            $links[] = ['x-default', $s['url'] . $path];
            break;
        }
    }
    return $links;
}

/* ---------- Zona waktu admin (default WIB) ---------- */

function admin_tz(): DateTimeZone
{
    try {
        return new DateTimeZone((string)gget('admin_tz', 'Asia/Jakarta'));
    } catch (Throwable) {
        return new DateTimeZone('Asia/Jakarta');
    }
}

/** "2026-10-22T08:00" (waktu admin) → "2026-10-22 01:00:00" (UTC) */
function admin_to_utc(string $local): ?string
{
    $local = trim($local);
    if ($local === '') {
        return null;
    }
    try {
        $d = new DateTime(str_replace('T', ' ', $local), admin_tz());
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

/** UTC → waktu admin, format tampil / input */
function utc_to_admin(?string $utc, string $format = 'Y-m-d H:i'): string
{
    if (!$utc) {
        return '';
    }
    try {
        return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(admin_tz())->format($format);
    } catch (Throwable) {
        return '';
    }
}
