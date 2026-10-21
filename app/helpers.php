<?php
declare(strict_types=1);

function e(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string)cfg('site.url'), '/') . '/' . ltrim($path, '/');
}

const LANGS = [
    'id' => 'Bahasa Indonesia', 'en' => 'English', 'de' => 'Deutsch', 'es' => 'Español', 'fr' => 'Français',
    'nl' => 'Nederlands', 'zh' => '中文 (简体)', 'ko' => '한국어', 'ja' => '日本語', 'hi' => 'हिन्दी',
];

function site_lang(): string
{
    $l = (string)cfg('site.lang', 'en');
    return isset(LANGS[$l]) ? $l : 'en';
}

/** Teks tampilan sesuai bahasa situs. {niche}/{item} diganti dari pengaturan situs. */
function t(string $key, mixed ...$args): string
{
    $site = Site::$current;
    $L = $site?->lang;
    if ($L === null) {
        $en = require ROOT . '/app/lang/en.php';
        $lang = site_lang();
        $L = $lang === 'en' ? $en : array_merge($en, require ROOT . '/app/lang/' . $lang . '.php');
        if ($site) {
            $site->lang = $L;
        }
    }
    $s = $L[$key] ?? $key;
    if (!is_string($s)) {
        return '';
    }
    if (str_contains($s, '{')) {
        $niche = trim((string)cfg('site.niche', '')) ?: (string)($L['_niche'] ?? '');
        $item = trim((string)cfg('site.item', '')) ?: (string)($L['_item'] ?? '');
        $startsWithPlaceholder = $s[0] === '{';
        $s = strtr($s, ['{niche}' => str_replace('%', '%%', $niche), '{item}' => str_replace('%', '%%', $item)]);
        if ($startsWithPlaceholder) {
            $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
        }
    }
    return $args ? vsprintf($s, $args) : $s;
}

/** Nilai mentah dari file bahasa (mis. array satuan angka). */
function lang_value(string $key): mixed
{
    t('_niche');
    $L = Site::$current?->lang ?? require ROOT . '/app/lang/en.php';
    return $L[$key] ?? null;
}

/** Atribut lang HTML, mis. de-CH, fr-CA, zh-Hans. */
function html_lang(): string
{
    $lang = site_lang();
    if ($lang === 'zh') {
        return 'zh-Hans';
    }
    $c = strtoupper((string)cfg('site.country', ''));
    return $lang . (preg_match('/^[A-Z]{2}$/', $c) ? '-' . $c : '');
}

function locale_code(): string
{
    $lang = site_lang();
    $c = strtoupper((string)cfg('site.country', ''));
    $default = ['en' => 'US', 'zh' => 'CN', 'hi' => 'IN', 'ko' => 'KR', 'ja' => 'JP', 'id' => 'ID',
        'de' => 'DE', 'es' => 'ES', 'fr' => 'FR', 'nl' => 'NL'][$lang] ?? 'US';
    return $lang . '_' . (preg_match('/^[A-Z]{2}$/', $c) ? $c : $default);
}

function slugify(string $text, int $max = 80): string
{
    $t = $text;
    if (function_exists('transliterator_transliterate')) {
        $t = (string)transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $t);
    } else {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        $t = strtolower($conv !== false ? $conv : $t);
    }
    $t = trim((string)preg_replace('/[^a-z0-9]+/', '-', $t), '-');
    if (strlen($t) > $max) {
        $t = rtrim(substr($t, 0, $max), '-');
    }
    return $t !== '' ? $t : 'video';
}

/** Slug unik & stabil: judul + 6 karakter hash ID. */
function make_slug(string $title, string $id): string
{
    return slugify($title) . '-' . substr(md5($id), 0, 6);
}

function iso_duration_to_seconds(string $iso): int
{
    if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $iso, $m)) {
        return 0;
    }
    return (int)($m[1] ?? 0) * 86400 + (int)($m[2] ?? 0) * 3600 + (int)($m[3] ?? 0) * 60 + (int)($m[4] ?? 0);
}

function seconds_to_iso(int $s): string
{
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    return 'PT' . ($h ? $h . 'H' : '') . ($m ? $m . 'M' : '') . ($s % 60) . 'S';
}

function fmt_duration(int $s): string
{
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    return $h ? sprintf('%d:%02d:%02d', $h, $m, $s % 60) : sprintf('%d:%02d', $m, $s % 60);
}

function fmt_number(int $n): string
{
    foreach ((array)lang_value('num_units') as [$unit, $suffix]) {
        if ($n >= $unit) {
            return str_replace('.', (string)lang_value('dec_point'), (string)round($n / $unit, 1)) . $suffix;
        }
    }
    return (string)$n;
}

function fmt_date(?string $d): string
{
    if (!$d || ($ts = strtotime($d . ' UTC')) === false) {
        return '';
    }
    if (class_exists('IntlDateFormatter')) {
        $f = new IntlDateFormatter(locale_code(), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
        $out = $f->format($ts);
        if (is_string($out) && $out !== '') {
            return $out;
        }
    }
    return gmdate('Y-m-d', $ts);
}

/** Bersihkan deskripsi YouTube: hapus link, hashtag, dan baris promosi kosong. */
function clean_description(?string $d): string
{
    $lines = [];
    foreach (preg_split('/\R/u', (string)$d) ?: [] as $line) {
        // Baris promosi pendek yang berisi link ("Download app: https://…") dibuang seluruhnya
        if (preg_match('~https?://~u', $line) && mb_strlen(trim((string)preg_replace('~https?://\S+~u', '', $line))) < 40) {
            continue;
        }
        $lines[] = $line;
    }
    $d = implode("\n", $lines);
    $d = preg_replace('~https?://\S+~u', '', $d);
    $d = preg_replace('/(^|\s)#[\p{L}\p{N}_]+/u', '$1', (string)$d);
    $d = preg_replace("/[ \t]+/u", ' ', (string)$d);
    $d = preg_replace("/\n{3,}/u", "\n\n", (string)$d);
    return trim((string)$d);
}

function excerpt(?string $text, int $len = 160): string
{
    $t = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len - 1)) . '…' : $t;
}

function paragraphs(string $text): string
{
    $out = '';
    foreach (preg_split("/\n\s*\n/u", trim($text)) ?: [] as $p) {
        if (trim($p) !== '') {
            $out .= '<p>' . nl2br(e(trim($p))) . '</p>';
        }
    }
    return $out;
}

function yt_thumb(string $id, string $quality = 'hqdefault'): string
{
    return 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/' . $quality . '.jpg';
}

function view(string $__view, array $__data = []): string
{
    extract($__data, EXTR_SKIP);
    ob_start();
    require ROOT . '/app/views/' . $__view . '.php';
    return (string)ob_get_clean();
}

/* ---------- Iklan ---------- */

/**
 * Mode iklan:
 *  adsense → hanya AdSense (auto ads + unit di slot). Popunder/smartlink & tombol CPA dimatikan.
 *  network → jaringan CPM/CPA (Adsterra, Monetag, dll.): semua slot + body_end + tombol CPA. Auto ads AdSense mati.
 *  custom  → semua aktif (risiko: AdSense bisa menolak/banned bila digabung popunder).
 */
function ads_mode(): string
{
    $m = (string)cfg('ads.mode', 'adsense');
    return in_array($m, ['adsense', 'network', 'custom'], true) ? $m : 'adsense';
}

function ad_allowed(string $slot): bool
{
    if (in_array($slot, ['body_end', 'cta'], true)) {
        return ads_mode() !== 'adsense';
    }
    return true;
}

function ad(string $slot): string
{
    $code = trim((string)cfg('ads.' . $slot, ''));
    if ($code === '' || !ad_allowed($slot)) {
        return '';
    }
    $label = cfg('ads.label', true) && $slot !== 'head' ? '<span class="ad-label">' . e(t('ad_label')) . '</span>' : '';
    return '<div class="ad ad-' . e($slot) . '">' . $label . $code . '</div>';
}

function adsense_client(): string
{
    $c = trim((string)cfg('ads.adsense_client', ''));
    if ($c === '' || ads_mode() === 'network') {
        return '';
    }
    $c = preg_replace('/^(ca-)?pub-/', '', $c);
    return preg_match('/^\d{10,20}$/', (string)$c) ? 'ca-pub-' . $c : '';
}

function adsense_head(): string
{
    $client = adsense_client();
    return $client === '' ? '' : '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client='
        . e($client) . '" crossorigin="anonymous"></script>';
}

/** Isi ads.txt: teks dari admin + baris AdSense otomatis bila belum ada. */
function ads_txt(): string
{
    $txt = trim(str_replace("\r", '', (string)cfg('ads.ads_txt', '')));
    $client = adsense_client();
    if ($client !== '') {
        $pub = substr($client, 3);
        if (!str_contains($txt, $pub)) {
            $txt = 'google.com, ' . $pub . ', DIRECT, f08c47fec0942fa0' . ($txt !== '' ? "\n" . $txt : '');
        }
    }
    return $txt . "\n";
}

function cta_box(): string
{
    if (!ad_allowed('cta') || !cfg('ads.cta_enabled') || trim((string)cfg('ads.cta_url', '')) === '') {
        return '';
    }
    $url = (string)cfg('ads.cta_url');
    if (!preg_match('~^https?://~i', $url)) {
        return '';
    }
    $text = trim((string)cfg('ads.cta_text', '')) ?: t('cta_default');
    $note = trim((string)cfg('ads.cta_note', ''));
    return '<div class="cta"><span class="ad-label">' . e(t('sponsored')) . '</span>'
        . '<a class="cta-btn" href="' . e($url) . '" target="_blank" rel="sponsored nofollow noopener">' . e($text) . '</a>'
        . ($note !== '' ? '<p class="cta-note">' . e($note) . '</p>' : '') . '</div>';
}

/* ---------- Topik dari keyword situs ---------- */

/** @return array<string, string> slug => keyword */
function site_topics(): array
{
    $out = [];
    foreach ((array)cfg('site.keywords', []) as $kw) {
        $kw = trim((string)$kw);
        if ($kw !== '') {
            $out[slugify($kw, 60)] = $kw;
        }
    }
    return $out;
}

function json_ld(array $data): string
{
    return '<script type="application/ld+json">'
        . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
        . '</script>';
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function logmsg(string $msg): void
{
    $prefix = Site::$current ? '[' . Site::$current->id . '] ' : '';
    $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $prefix . $msg . PHP_EOL;
    if (PHP_SAPI === 'cli') {
        echo $line;
    }
    if (Site::$current) {
        $f = Site::$current->dir('logs') . '/cron.log';
        if (is_file($f) && filesize($f) > 2_000_000) {
            @rename($f, $f . '.1');
        }
        @file_put_contents($f, $line, FILE_APPEND);
    }
}

function log_tail(int $lines = 40): string
{
    $f = current_site()->dir('logs') . '/cron.log';
    if (!is_file($f)) {
        return '';
    }
    $all = file($f, FILE_IGNORE_NEW_LINES) ?: [];
    return implode("\n", array_slice($all, -$lines));
}

/* ---------- Cache halaman (file, per situs) ---------- */

function cache_path(string $key): string
{
    return current_site()->dir('cache') . '/' . substr($key, 0, 2) . '/' . $key . '.cache';
}

/** @return array{type:string,body:string}|null */
function cache_get(string $key): ?array
{
    $ttl = (int)cfg('site.cache_ttl', 0);
    $f = cache_path($key);
    if ($ttl <= 0 || !is_file($f) || filemtime($f) < time() - $ttl) {
        return null;
    }
    $raw = (string)@file_get_contents($f);
    $pos = strpos($raw, "\n");
    return $pos === false ? null : ['type' => substr($raw, 0, $pos), 'body' => substr($raw, $pos + 1)];
}

function cache_put(string $key, string $type, string $body): void
{
    if ((int)cfg('site.cache_ttl', 0) <= 0) {
        return;
    }
    $f = cache_path($key);
    @mkdir(dirname($f), 0775, true);
    @file_put_contents($f . '.tmp', $type . "\n" . $body);
    @rename($f . '.tmp', $f);
}

function cache_clear(): void
{
    foreach (glob(current_site()->dir('cache') . '/*/*.cache') ?: [] as $f) {
        @unlink($f);
    }
}

/** Impressum wajib untuk situs berbahasa Jerman; tampil juga bila legal.name diisi. */
function show_impressum(): bool
{
    return cfg('site.lang') === 'de' || trim((string)cfg('legal.name', '')) !== '';
}

/** Argumen halaman statis: %1$s nama situs, %2$s email, %3$s nama pemilik, %4$s alamat (sudah di-escape). */
function legal_args(): array
{
    $name = trim((string)cfg('legal.name', ''));
    $address = trim((string)cfg('legal.address', ''));
    return [
        e(cfg('site.name')),
        e(cfg('site.contact_email')),
        $name !== '' ? e($name) : '—',
        $address !== '' ? nl2br(e(str_replace(['\\n', ' | '], "\n", $address))) : '—',
    ];
}

function tagline(): string
{
    return trim((string)cfg('site.tagline', '')) ?: t('tagline_default');
}

/**
 * Ubah input channel (URL, @handle, atau ID) jadi bentuk yang dipakai API: "@handle" atau "UC...".
 * Return '' bila tidak dikenali.
 */
function normalize_channel_ref(string $in): string
{
    $in = trim($in);
    if (preg_match('~youtube\.com/channel/(UC[\w-]{22})~i', $in, $m) || preg_match('~^(UC[\w-]{22})$~', $in, $m)) {
        return $m[1];
    }
    if (preg_match('~youtube\.com/@([\w.\-·]+)~iu', $in, $m) || preg_match('~^@?([\w.\-·]{3,100})$~u', $in, $m)) {
        return '@' . $m[1];
    }
    return '';
}

/* ---------- Video dari berbagai sumber ---------- */

function video_source(array $v): string
{
    return (string)($v['source'] ?? '') ?: 'youtube';
}

function source_name(string $source): string
{
    return ['youtube' => 'YouTube', 'dailymotion' => 'Dailymotion', 'archive' => 'Internet Archive', 'nasa' => 'NASA', 'tiktok' => 'TikTok'][$source] ?? $source;
}

function video_thumb(array $v, string $quality = 'mqdefault'): string
{
    if (video_source($v) === 'youtube') {
        return yt_thumb((string)$v['id'], $quality);
    }
    return (string)($v['thumb'] ?? '');
}

function video_embed_url(array $v): string
{
    $mode = (string)cfg('player.subtitles', 'auto');
    $l = site_lang();
    if (video_source($v) === 'youtube') {
        $url = 'https://www.youtube-nocookie.com/embed/' . rawurlencode((string)$v['id']);
        if ($mode === 'off') {
            return $url;
        }
        // hl = bahasa tombol player; cc_lang_pref = bahasa subtitle yang dipilih bila tersedia
        $q = ['hl' => $l === 'zh' ? 'zh-CN' : $l, 'cc_lang_pref' => $l === 'zh' ? 'zh-Hans' : $l];
        if (video_force_subs($v)) {
            $q['cc_load_policy'] = 1;
        }
        return $url . '?' . http_build_query($q);
    }
    $url = (string)($v['embed_url'] ?? '');
    if (video_source($v) === 'dailymotion' && $url !== '' && video_force_subs($v)) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'subtitles-default=' . rawurlencode($l);
    }
    return $url;
}

function video_source_url(array $v): string
{
    if (video_source($v) === 'youtube') {
        return 'https://www.youtube.com/watch?v=' . rawurlencode((string)$v['id']);
    }
    return (string)($v['source_url'] ?? '');
}

function is_public_domain_source(string $source): bool
{
    return in_array($source, ['archive', 'nasa'], true);
}

/** Situs ini memakai sumber selain YouTube? (untuk teks privasi) */
function site_uses_other_sources(): bool
{
    return (bool)array_filter((array)cfg('sources', []), fn($s) => trim((string)($s['value'] ?? '')) !== '');
}

/* ---------- Markdown sederhana & aman untuk artikel ---------- */

/**
 * Format yang didukung: ## Judul, ### Subjudul, **tebal**, *miring*, [teks](https://link), ![alt](https://gambar),
 * daftar "- " dan "1. ", kutipan "> ", serta baris khusus [video:slug-video] untuk menyisipkan kartu video.
 * Slot iklan "in_article" otomatis disisipkan setelah paragraf ke-2.
 */
function md_render(string $md, string $midHtml = '', bool $leftoverAtEnd = true): string
{
    $md = str_replace("\r", '', trim($md));
    $blocks = preg_split("/\n{2,}/", $md) ?: [];
    $html = '';
    $paras = 0;
    $blocks = array_values(array_filter(array_map('trim', $blocks), fn($b) => $b !== ''));
    $total = count($blocks);
    foreach ($blocks as $i => $b) {
        if ($b === '') {
            continue;
        }
        if (preg_match('/^\[video:\s*([a-z0-9-]{1,120})\s*\]$/', $b, $m)) {
            $v = video_by_slug_light($m[1]);
            $html .= $v ? '<div class="article-video">' . view('partials/video_card', ['v' => $v]) . '</div>' : '';
            continue;
        }
        if (preg_match('/^(#{2,3})\s+(.+)$/', $b, $m)) {
            $tag = strlen($m[1]) === 2 ? 'h2' : 'h3';
            $html .= "<$tag>" . md_inline($m[2]) . "</$tag>";
            continue;
        }
        $lines = explode("\n", $b);
        if (count(array_filter($lines, fn($l) => preg_match('/^\s*[-*]\s+/', $l))) === count($lines)) {
            $html .= '<ul>' . implode('', array_map(fn($l) => '<li>' . md_inline((string)preg_replace('/^\s*[-*]\s+/', '', $l)) . '</li>', $lines)) . '</ul>';
            continue;
        }
        if (count(array_filter($lines, fn($l) => preg_match('/^\s*\d+[.)]\s+/', $l))) === count($lines)) {
            $html .= '<ol>' . implode('', array_map(fn($l) => '<li>' . md_inline((string)preg_replace('/^\s*\d+[.)]\s+/', '', $l)) . '</li>', $lines)) . '</ol>';
            continue;
        }
        if (str_starts_with($b, '>')) {
            $html .= '<blockquote>' . md_inline((string)preg_replace('/^>\s?/m', '', $b)) . '</blockquote>';
            continue;
        }
        $html .= '<p>' . implode('<br>', array_map('md_inline', $lines)) . '</p>';
        if (++$paras === 2) {
            $html .= ad('in_article');
        }
        // CTA tengah: setelah paragraf ke-4, asal masih ada isi setelahnya (bukan menempel di akhir)
        if ($paras >= 4 && $midHtml !== '' && $total - $i - 1 >= 3) {
            $html .= $midHtml;
            $midHtml = '';
        }
    }
    return $leftoverAtEnd ? $html . $midHtml : $html;   // artikel pendek: CTA tengah pindah ke akhir
}

/**
 * Penawaran (CTA) untuk sebuah artikel: pakai isian artikel itu, atau default situs (menu Iklan).
 * @return array{html:string, pos:string}
 */
function article_cta(array $a): array
{
    if (!empty($a['cta_off'])) {
        return ['html' => '', 'pos' => ''];
    }
    if (ads_mode() === 'adsense' && !cfg('ads.acta_in_adsense')) {
        return ['html' => '', 'pos' => ''];
    }
    $own = trim((string)($a['cta_url'] ?? '')) !== '';
    if (!$own && !cfg('ads.acta_enabled')) {
        return ['html' => '', 'pos' => ''];
    }
    $pick = fn(string $k) => trim((string)($own ? ($a['cta_' . $k] ?? '') : cfg('ads.acta_' . $k, '')));
    $url = $pick('url');
    if (!preg_match('~^https?://\S+$~i', $url)) {
        return ['html' => '', 'pos' => ''];
    }
    $title = $pick('title');
    $note = $pick('note');
    $html = '<aside class="cta cta-article"><span class="ad-label">' . e(t('sponsored')) . '</span>'
        . ($title !== '' ? '<p class="cta-title">' . e($title) . '</p>' : '')
        . '<a class="cta-btn" href="' . e($url) . '" target="_blank" rel="sponsored nofollow noopener">' . e($pick('text') ?: t('cta_default')) . '</a>'
        . ($note !== '' ? '<p class="cta-note">' . e($note) . '</p>' : '') . '</aside>';
    $pos = (string)cfg('ads.acta_pos', 'both');
    return ['html' => $html, 'pos' => in_array($pos, ['middle', 'end', 'both'], true) ? $pos : 'both'];
}

function md_inline(string $s): string
{
    $s = e($s);
    // `kode` / rumus (penting untuk niche tutorial) — diamankan dulu agar tidak diubah oleh aturan lain
    $codes = [];
    $s = (string)preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$codes) {
        $codes[] = '<code>' . $m[1] . '</code>';
        return "\x00" . (count($codes) - 1) . "\x00";
    }, $s);
    $s = (string)preg_replace_callback('/!\[([^\]]*)\]\((https:\/\/[^\s)]+)\)/', fn($m) =>
        '<img src="' . $m[2] . '" alt="' . $m[1] . '" loading="lazy">', $s);
    $s = (string)preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]+)\)/', function ($m) {
        $ext = !str_starts_with($m[2], '/') && !str_starts_with(html_entity_decode($m[2]), rtrim((string)cfg('site.url'), '/'));
        return '<a href="' . $m[2] . '"' . ($ext ? ' target="_blank" rel="noopener nofollow"' : '') . '>' . $m[1] . '</a>';
    }, $s);
    $s = (string)preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    $s = (string)preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '<em>$1</em>', $s);
    return $codes ? (string)preg_replace_callback('/\x00(\d+)\x00/', fn($m) => $codes[(int)$m[1]] ?? '', $s) : $s;
}
