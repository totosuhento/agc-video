<?php
declare(strict_types=1);

/* ================= Proteksi halaman tipis ================= */

/**
 * Halaman video boleh diindeks Google?
 * Mode "smart" (default): hanya bila sudah punya deskripsi unik (AI / ditulis sendiri),
 * karena halaman yang isinya sama persis dengan YouTube dianggap konten tipis.
 */
function video_indexable(array $v): bool
{
    return cfg('seo.video_index', 'smart') === 'all' || trim((string)($v['ai_summary'] ?? '')) !== '';
}

/** Kondisi SQL untuk video yang layak masuk sitemap. */
function video_index_sql(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    return cfg('seo.video_index', 'smart') === 'all' ? '1 = 1' : "({$p}ai_summary IS NOT NULL AND {$p}ai_summary <> '')";
}

/* ================= Verifikasi mesin pencari ================= */

const SEO_VERIFY = [
    'google_verify' => ['google-site-verification', 'Google Search Console'],
    'bing_verify' => ['msvalidate.01', 'Bing Webmaster Tools'],
    'naver_verify' => ['naver-site-verification', 'Naver Search Advisor (Korea)'],
    'yandex_verify' => ['yandex-verification', 'Yandex Webmaster'],
];

/** Terima kode saja atau seluruh tag <meta ... content="..."> yang ditempel dari Search Console. */
function seo_verify_code(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $raw, $m)) {
        $raw = $m[1];
    }
    return preg_match('/^[A-Za-z0-9_.:=+\/-]{4,120}$/', $raw) ? $raw : '';
}

function seo_verify_meta(): string
{
    $out = '';
    foreach (SEO_VERIFY as $k => [$name]) {
        $code = seo_verify_code((string)cfg('seo.' . $k, ''));
        if ($code !== '') {
            $out .= '<meta name="' . $name . '" content="' . e($code) . '">' . "\n";
        }
    }
    return $out;
}

/* ================= IndexNow (Bing, Yandex, Naver, Seznam) ================= */

function indexnow_key(): string
{
    $k = (string)meta_get('indexnow_key', '');
    if (!preg_match('/^[a-f0-9]{32}$/', $k)) {
        $k = random_token(16);
        meta_set('indexnow_key', $k);
    }
    return $k;
}

/** Kirim URL baru/berubah. Return jumlah URL yang dikirim. */
function indexnow_submit(array $urls): int
{
    $base = rtrim((string)cfg('site.url'), '/');
    $host = (string)parse_url($base, PHP_URL_HOST);
    $urls = array_values(array_unique(array_filter($urls, fn($u) => str_starts_with((string)$u, $base . '/'))));
    if (!cfg('seo.indexnow', true) || !$urls || $host === '' || !str_starts_with($base, 'https://')
        || in_array($host, ['localhost', '127.0.0.1'], true)) {
        return 0;
    }
    $key = indexnow_key();
    $sent = 0;
    foreach (array_chunk($urls, 1000) as $chunk) {
        $body = json_encode(['host' => $host, 'key' => $key, 'keyLocation' => $base . '/' . $key . '.txt', 'urlList' => $chunk], JSON_UNESCAPED_SLASHES);
        if (function_exists('indexnow_http_hook')) {
            $code = indexnow_http_hook($body);   // pengujian
        } else {
            $ch = curl_init('https://api.indexnow.org/indexnow');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'], CURLOPT_POSTFIELDS => $body,
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        if ($code === 200 || $code === 202) {
            $sent += count($chunk);
        } else {
            logmsg("IndexNow ditolak (HTTP $code). Pastikan " . $base . '/' . $key . '.txt bisa dibuka.');
            break;
        }
    }
    if ($sent) {
        logmsg("IndexNow: $sent URL dikirim ke Bing/Yandex/Naver");
    }
    return $sent;
}

/** Dipanggil cron tiap jam: artikel yang baru terbit (termasuk jadwal) + video baru yang layak index. */
function indexnow_ping_new(): int
{
    if (!cfg('seo.indexnow', true)) {
        return 0;
    }
    $last = meta_get('indexnow_last');
    $now = now();
    meta_set('indexnow_last', $now);
    if ($last === null) {
        return 0;   // jalan pertama: hanya mulai mencatat, tidak membanjiri
    }
    $urls = [];
    foreach (q("SELECT slug FROM articles WHERE status = 'published' AND published_at > ? AND published_at <= ? LIMIT 500", [$last, $now])->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $urls[] = url('article/' . $s);
    }
    if ($urls) {
        $urls[] = url('articles');
    }
    foreach (q('SELECT slug FROM videos WHERE is_active = 1 AND created_at > ? AND ' . video_index_sql() . ' LIMIT 500', [$last])->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $urls[] = url('video/' . $s);
    }
    return $urls ? indexnow_submit($urls) : 0;
}

/* ================= Internal link ================= */

/**
 * Artikel yang relevan dengan sebuah teks (judul video / artikel): kata kunci artikel muncul di teks,
 * atau kata kunci teks muncul di judul artikel. Sisanya diisi artikel terbaru.
 */
function articles_related(string $text, int $limit = 3, string $excludeSlug = '', bool $fillLatest = true): array
{
    static $all = null;
    $all ??= q("SELECT id, slug, title, excerpt, cover, keyword, published_at FROM articles
                WHERE status = 'published' AND published_at <= ? ORDER BY published_at DESC LIMIT 500", [now()])->fetchAll();
    $hit = $rest = [];
    foreach ($all as $a) {
        if ($a['slug'] === $excludeSlug) {
            continue;
        }
        $kw = trim((string)$a['keyword']);
        if ($kw !== '' && mb_strlen($kw) >= 3 && mb_stripos($text, $kw) !== false) {
            $hit[] = $a;
        } else {
            $rest[] = $a;
        }
    }
    return array_slice($fillLatest ? array_merge($hit, $rest) : $hit, 0, $limit);
}

/* ================= Bahasa video & subtitle ================= */

/** Normalisasi kode bahasa: "de-DE" → "de", "zh-Hans" → "zh", "eng"/"English" → "en". Kosong bila tidak diketahui. */
function lang_base(?string $code): string
{
    $c = strtolower(trim((string)$code));
    if ($c === '' || in_array($c, ['zxx', 'und', 'mul', 'mis'], true)) {
        return '';
    }
    $map = ['eng' => 'en', 'english' => 'en', 'ger' => 'de', 'deu' => 'de', 'german' => 'de', 'fre' => 'fr', 'fra' => 'fr', 'french' => 'fr',
        'spa' => 'es', 'spanish' => 'es', 'dut' => 'nl', 'nld' => 'nl', 'dutch' => 'nl', 'jpn' => 'ja', 'japanese' => 'ja',
        'kor' => 'ko', 'korean' => 'ko', 'chi' => 'zh', 'zho' => 'zh', 'chinese' => 'zh', 'hin' => 'hi', 'hindi' => 'hi',
        'ind' => 'id', 'indonesian' => 'id', 'in' => 'id', 'iw' => 'he', 'ita' => 'it', 'italian' => 'it', 'por' => 'pt', 'portuguese' => 'pt',
        'deutsch' => 'de', 'español' => 'es', 'espanol' => 'es', 'français' => 'fr', 'francais' => 'fr', 'nederlands' => 'nl',
        'indonesia' => 'id', 'bahasa indonesia' => 'id', 'inggris' => 'en', 'jerman' => 'de', 'korea' => 'ko', 'jepang' => 'ja'];
    if (isset($map[$c])) {
        return $map[$c];
    }
    $c = (string)preg_split('/[-_]/', $c)[0];
    $known = ['id', 'en', 'de', 'es', 'fr', 'nl', 'zh', 'ko', 'ja', 'hi', 'it', 'pt', 'ru', 'ar', 'tr', 'th', 'vi', 'ms', 'tl', 'fil', 'pl', 'sv',
        'da', 'no', 'nb', 'fi', 'he', 'el', 'cs', 'hu', 'ro', 'uk', 'bn', 'ta', 'te', 'ur', 'fa', 'jv', 'su'];
    return $map[$c] ?? (in_array($c, $known, true) ? $c : '');
}

/** Filter "Bahasa suara video": video dengan bahasa yang DIKETAHUI dan tidak diizinkan → disembunyikan. */
function video_lang_ok(?string $lang): bool
{
    $allow = array_filter(array_map('lang_base', (array)cfg('youtube.audio_langs', [])));
    $l = lang_base($lang);
    return !$allow || $l === '' || in_array($l, $allow, true);
}

/** Paksa subtitle menyala untuk video ini? */
function video_force_subs(array $v): bool
{
    $mode = (string)cfg('player.subtitles', 'auto');
    if ($mode === 'always') {
        return true;
    }
    $l = lang_base($v['lang'] ?? '');
    return $mode === 'auto' && $l !== '' && $l !== site_lang();
}
